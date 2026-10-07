<?php

declare(strict_types=1);

require_once __DIR__.'/../AccountingCollectorsFixture.php';

use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\IntegrationResource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Models\Integrations\NetsuiteIntegration;
use App\Models\IntegrationMappings\NetsuiteMapping;
use App\Models\IntegrationCustomers\NetsuiteCustomer;
use App\Jobs\Integrations\Aggregator\CreditNotes\CreateJob;
use App\Services\Integrations\Aggregator\CreditNotes\CreateService;

/**
 * Port of Rails' spec/services/integrations/aggregator/credit_notes/
 * create_service_spec.rb — the guard clauses, the IntegrationResource
 * recording and the error legs.
 */
function accountingCreditNoteFixture(Organization $organization, array $integrationAttributes = []): array
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'cus_lago_1',
    ]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create($integrationAttributes);
    $integrationCustomer = NetsuiteCustomer::factory()->forIntegration($integration)->forCustomer($customer)->create();

    $creditNote = App\Models\CreditNote::factory()->forInvoice($invoice)->finalized()->create();

    return [$customer, $invoice, $integration, $integrationCustomer, $creditNote];
}

beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset($_ENV['NANGO_SECRET_KEY']);
});

it('returns without an accounting integration customer', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $creditNote = App\Models\CreditNote::factory()->forInvoice($invoice)->finalized()->create();

    Http::fake();

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('returns when sync_credit_notes is off', function (): void {
    $organization = Organization::factory()->create();
    [, , $integration, , $creditNote] = accountingCreditNoteFixture($organization, [
        'settings' => ['sync_credit_notes' => false],
    ]);

    Http::fake();

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('returns for a non-finalized credit note', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingCreditNoteFixture($organization);
    $creditNote = App\Models\CreditNote::factory()->forInvoice($invoice)->draft()->create();

    Http::fake();

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue();

    Http::assertNothingSent();
});

it('returns when the credit note is already synced', function (): void {
    $organization = Organization::factory()->create();
    [, , $integration, , $creditNote] = accountingCreditNoteFixture($organization);

    IntegrationResource::query()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'external_id' => 'ns-credit-note-1',
        'syncable_id' => $creditNote->id,
        'syncable_type' => 'CreditNote',
        'resource_type' => IntegrationResource::RESOURCE_TYPE_CREDIT_NOTE,
    ]);

    Http::fake();

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('records the external id and the integration resource on a hash success', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingCreditNoteFixture($organization);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    Http::fake([
        'https://api.nango.dev/v1/netsuite/creditnotes' => Http::response([
            'succeededCreditNotes' => [['id' => 'e5a62e05-e192-489f-8965-e01b597b523b']],
        ]),
    ]);

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBe('e5a62e05-e192-489f-8965-e01b597b523b');

    $integrationResource = IntegrationResource::query()
        ->where('syncable_type', 'CreditNote')
        ->where('syncable_id', $creditNote->id)
        ->first();

    expect($integrationResource->syncable_id)->toBe($creditNote->id)
        ->and($integrationResource->syncable_type)->toBe('CreditNote')
        ->and((int) $integrationResource->resource_type)->toBe(IntegrationResource::RESOURCE_TYPE_CREDIT_NOTE);
});

it('records the external id on a string success', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingCreditNoteFixture($organization);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    Http::fake([
        'https://api.nango.dev/v1/netsuite/creditnotes' => Http::response('"456"'),
    ]);

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBe('456');

    expect(IntegrationResource::query()->where('syncable_type', 'CreditNote')->where('syncable_id', $creditNote->id)->count())->toBe(1);
});

it('delivers the validation error webhook and records nothing on a failed hash', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingCreditNoteFixture($organization);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    Queue::fake();

    Http::fake([
        'https://api.nango.dev/v1/netsuite/creditnotes' => Http::response([
            'failedCreditNotes' => [['validation_errors' => [['Message' => 'Invalid entity']]]],
        ]),
    ]);

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    expect(IntegrationResource::query()->where('syncable_type', 'CreditNote')->count())->toBe(0);

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'customer.accounting_provider_error');
});

it('delivers the error webhook and rethrows on a server error', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingCreditNoteFixture($organization);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    Queue::fake();

    Http::fake(function (Request $request) {
        if ($request->url() !== 'https://api.nango.dev/v1/netsuite/creditnotes') {
            return Http::response('', 500);
        }

        return Http::response(['error' => ['message' => 'boom']], 500);
    });

    expect(fn () => CreateService::call(credit_note: $creditNote))->toThrow(App\Http\Client\LagoHttpError::class);

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'customer.accounting_provider_error');
});

it('delivers the error webhook and answers on a client error', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingCreditNoteFixture($organization);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $integration->organization_id,
    ]);

    Queue::fake();

    Http::fake(function (Request $request) {
        if ($request->url() !== 'https://api.nango.dev/v1/netsuite/creditnotes') {
            return Http::response('', 500);
        }

        return Http::response(['error' => ['message' => 'Invalid entity']], 400);
    });

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'customer.accounting_provider_error');
});

it('delivers the invalid_mapping webhook when the fee has no mapping', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = accountingCreditNoteFixture($organization);
    [$metric, $creditNote] = accountingChargeCreditNote($organization, $customer, $invoice);

    Queue::fake();

    Http::fake();

    $result = CreateService::call(credit_note: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, function ($job) use ($customer): bool {
        return $job->object?->id === $customer->id
            && $job->webhookType === 'customer.accounting_provider_error'
            && $job->options['provider_error']['error_code'] === 'invalid_mapping';
    });
});

it('enqueues the create job through call_async', function (): void {
    $organization = Organization::factory()->create();
    [, , , , $creditNote] = accountingCreditNoteFixture($organization);

    Queue::fake();

    $result = (new CreateService($creditNote))->call_async();

    expect($result->success())->toBeTrue()
        ->and($result->credit_note_id)->toBe($creditNote->id);

    Queue::assertPushed(CreateJob::class);
});

it('answers a not-found failure through call_async without a credit note', function (): void {
    Queue::fake();

    $result = (new CreateService(null))->call_async();

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('credit_note_not_found');

    Queue::assertNotPushed(CreateJob::class);
});
