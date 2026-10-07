<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use Illuminate\Support\Env;
use App\Models\Organization;
use App\Models\IntegrationResource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Models\Integrations\NetsuiteIntegration;
use App\Models\IntegrationCustomers\NetsuiteCustomer;
use App\Jobs\Integrations\Aggregator\Invoices\CreateJob;
use App\Services\Integrations\Aggregator\Invoices\CreateService;

/**
 * Port of Rails' spec/services/integrations/aggregator/invoices/
 * create_service_spec.rb — the guard clauses and the error legs (the
 * full-body happy path needs the collection-mappings slice, so every fee
 * mapping resolves to the "invalid_mapping" failure, like Rails with no
 * mappings configured).
 */
function accountingInvoiceFixture(Organization $organization): array
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'cus_lago_1',
    ]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create();
    $integrationCustomer = NetsuiteCustomer::factory()->forIntegration($integration)->forCustomer($customer)->create();

    return [$customer, $invoice, $integration, $integrationCustomer];
}

beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    Env::getRepository()->clear('NANGO_SECRET_KEY');

    unset($_ENV['NANGO_SECRET_KEY']);
});

it('returns without an accounting integration customer', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    Http::fake();

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('returns when sync_invoices is off', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingInvoiceFixture($organization);

    $settings = $integration->settings;
    $settings['sync_invoices'] = false;
    $integration->settings = $settings;
    $integration->save();

    Http::fake();

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('returns for a non-finalized invoice', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = accountingInvoiceFixture($organization);

    $invoice->update(['status' => App\Enums\InvoiceStatus::Draft]);

    Http::fake();

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('returns when the invoice is already synced', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingInvoiceFixture($organization);

    IntegrationResource::query()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'external_id' => 'ns-invoice-1',
        'syncable_id' => $invoice->id,
        'syncable_type' => 'Invoice',
        'resource_type' => IntegrationResource::RESOURCE_TYPE_INVOICE,
    ]);

    Http::fake();

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('delivers the error webhook and fails on the invalid fee mapping', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = accountingInvoiceFixture($organization);

    Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'fee_type' => App\Enums\FeeType::Subscription,
        'amount_cents' => 1000,
        'precise_amount_cents' => '1000',
    ]);

    Queue::fake();

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/v1/netsuite/invoices') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response('{}');
    });

    $result = CreateService::call(invoice: $invoice);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('invalid_mapping');

    // Rails: the BasePayload::Failure error webhook.
    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job): bool => $job->object?->id === $customer->id
        && $job->webhookType === 'customer.accounting_provider_error'
        && $job->options['provider_error']['error_code'] === 'invalid_mapping');
});

it('delivers the error webhook and fails on a provider validation error', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = accountingInvoiceFixture($organization);

    Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'fee_type' => App\Enums\FeeType::Subscription,
        'amount_cents' => 1000,
        'precise_amount_cents' => '1000',
    ]);

    Queue::fake();

    Http::fake(function ($request) {
        if ($request->url() !== 'https://api.nango.dev/v1/netsuite/invoices') {
            return Http::response('', 500);
        }

        return Http::response(['error' => ['message' => 'Invalid entity']], 400);
    });

    $result = CreateService::call(invoice: $invoice);

    expect($result->failure())->toBeTrue();

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'customer.accounting_provider_error');
});

it('enqueues through dispatchIfShouldSync on the accounting kind', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingInvoiceFixture($organization);

    CreateJob::dispatchIfShouldSync($invoice);

    Queue::assertPushed(CreateJob::class);
});

it('does not enqueue when the accounting integration does not sync invoices', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    [$customer, $invoice, $integration] = accountingInvoiceFixture($organization);

    $settings = $integration->settings;
    $settings['sync_invoices'] = false;
    $integration->settings = $settings;
    $integration->save();

    CreateJob::dispatchIfShouldSync($invoice);

    Queue::assertNotPushed(CreateJob::class);
});
