<?php

declare(strict_types=1);

require_once __DIR__.'/../AccountingCollectorsFixture.php';

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Models\IntegrationResource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Models\Integrations\NetsuiteIntegration;
use App\Models\IntegrationCustomers\NetsuiteCustomer;
use App\Jobs\Integrations\Aggregator\Payments\CreateJob;
use App\Services\Integrations\Aggregator\Payments\CreateService;

/**
 * Port of Rails' spec/services/integrations/aggregator/payments/
 * create_service_spec.rb — the guard clauses, the IntegrationResource
 * recording and the error legs.
 */
function accountingPaymentFixture(Organization $organization, array $integrationAttributes = []): array
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

    $payment = Payment::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'Invoice',
        'payable_id' => $invoice->id,
        'amount_cents' => 100,
    ]);

    return [$customer, $invoice, $integration, $integrationCustomer, $payment];
}

function accountingIntegrationInvoiceResource(Organization $organization, $integration, $invoice): void
{
    IntegrationResource::query()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'external_id' => 'ns-invoice-1',
        'syncable_id' => $invoice->id,
        'syncable_type' => 'Invoice',
        'resource_type' => IntegrationResource::RESOURCE_TYPE_INVOICE,
    ]);
}

beforeEach(function (): void {
    putenv('NANGO_SECRET_KEY=secret');
    $_ENV['NANGO_SECRET_KEY'] = 'secret';
});

afterEach(function (): void {
    putenv('NANGO_SECRET_KEY');
    unset(\Illuminate\Support\Env::get('NANGO_SECRET_KEY'));
});

it('returns without an accounting integration customer', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $payment = Payment::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'payable_type' => 'Invoice',
        'payable_id' => $invoice->id,
    ]);

    Http::fake();

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('returns when sync_payments is off', function (): void {
    $organization = Organization::factory()->create();
    [, , $integration, , $payment] = accountingPaymentFixture($organization, [
        'settings' => ['sync_payments' => false],
    ]);
    accountingIntegrationInvoiceResource($organization, $integration, $payment->payable);

    Http::fake();

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('returns for a non-finalized payable invoice', function (): void {
    $organization = Organization::factory()->create();
    [, $invoice, $integration, , $payment] = accountingPaymentFixture($organization);
    accountingIntegrationInvoiceResource($organization, $integration, $invoice);

    $invoice->update(['status' => InvoiceStatus::Draft]);

    Http::fake();

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue();

    Http::assertNothingSent();
});

it('returns when the payment is already synced', function (): void {
    $organization = Organization::factory()->create();
    [, $invoice, $integration, , $payment] = accountingPaymentFixture($organization);
    accountingIntegrationInvoiceResource($organization, $integration, $invoice);

    IntegrationResource::query()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'external_id' => 'ns-payment-1',
        'syncable_id' => $payment->id,
        'syncable_type' => 'Payment',
        'resource_type' => IntegrationResource::RESOURCE_TYPE_PAYMENT,
    ]);

    Http::fake();

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('records the external id and the integration resource on a string success', function (): void {
    $organization = Organization::factory()->create();
    [, $invoice, $integration, , $payment] = accountingPaymentFixture($organization);
    accountingIntegrationInvoiceResource($organization, $integration, $invoice);

    Http::fake([
        'https://api.nango.dev/v1/netsuite/payments' => Http::response('"999"'),
    ]);

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBe('999');

    $integrationResource = IntegrationResource::query()
        ->where('syncable_type', 'Payment')
        ->where('syncable_id', $payment->id)
        ->first();

    expect($integrationResource->syncable_id)->toBe($payment->id)
        ->and($integrationResource->syncable_type)->toBe('Payment')
        ->and((int) $integrationResource->resource_type)->toBe(IntegrationResource::RESOURCE_TYPE_PAYMENT);
});

it('records the external id on a hash success', function (): void {
    $organization = Organization::factory()->create();
    [, $invoice, $integration, , $payment] = accountingPaymentFixture($organization);
    accountingIntegrationInvoiceResource($organization, $integration, $invoice);

    Http::fake([
        'https://api.nango.dev/v1/netsuite/payments' => Http::response([
            'succeededPayment' => [['id' => 'e68f6095-f8d2-4d7a-ac05-7bb919d0330e']],
        ]),
    ]);

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBe('e68f6095-f8d2-4d7a-ac05-7bb919d0330e');
});

it('delivers the validation error webhook and records nothing on a failed hash', function (): void {
    $organization = Organization::factory()->create();
    [, $invoice, $integration, , $payment] = accountingPaymentFixture($organization);
    accountingIntegrationInvoiceResource($organization, $integration, $invoice);

    Queue::fake();

    Http::fake([
        'https://api.nango.dev/v1/netsuite/payments' => Http::response([
            'failedPayments' => [['validation_errors' => [['Message' => 'Invalid customer']]]],
        ]),
    ]);

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    expect(IntegrationResource::query()->where('syncable_type', 'Payment')->count())->toBe(0);

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'customer.accounting_provider_error');
});

it('fails with invoice_missing when the invoice never synced', function (): void {
    $organization = Organization::factory()->create();
    [$customer, , , , $payment] = accountingPaymentFixture($organization);

    Queue::fake();

    Http::fake();

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn($job): bool => $job->object?->id === $customer->id
        && $job->webhookType === 'customer.accounting_provider_error'
        && $job->options['provider_error']['error_code'] === 'invoice_missing');
});

it('delivers the error webhook and rethrows on a server error', function (): void {
    $organization = Organization::factory()->create();
    [, $invoice, $integration, , $payment] = accountingPaymentFixture($organization);
    accountingIntegrationInvoiceResource($organization, $integration, $invoice);

    Queue::fake();

    Http::fake(function (Request $request) {
        if ($request->url() !== 'https://api.nango.dev/v1/netsuite/payments') {
            return Http::response('', 500);
        }

        return Http::response(['error' => ['message' => 'boom']], 500);
    });

    expect(fn () => CreateService::call(payment: $payment))->toThrow(App\Http\Client\LagoHttpError::class);

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'customer.accounting_provider_error');
});

it('delivers the error webhook and answers on a client error', function (): void {
    $organization = Organization::factory()->create();
    [, $invoice, $integration, , $payment] = accountingPaymentFixture($organization);
    accountingIntegrationInvoiceResource($organization, $integration, $invoice);

    Queue::fake();

    Http::fake(function (Request $request) {
        if ($request->url() !== 'https://api.nango.dev/v1/netsuite/payments') {
            return Http::response('', 500);
        }

        return Http::response(['error' => ['message' => 'Invalid customer']], 400);
    });

    $result = CreateService::call(payment: $payment);

    expect($result->success())->toBeTrue()
        ->and($result->external_id)->toBeNull();

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'customer.accounting_provider_error');
});

it('enqueues the create job through call_async', function (): void {
    $organization = Organization::factory()->create();
    [, , , , $payment] = accountingPaymentFixture($organization);

    Queue::fake();

    $result = (new CreateService($payment))->call_async();

    expect($result->success())->toBeTrue()
        ->and($result->payment_id)->toBe($payment->id);

    Queue::assertPushed(CreateJob::class);
});

it('answers a not-found failure through call_async without a payment', function (): void {
    Queue::fake();

    $result = (new CreateService(null))->call_async();

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('payment_not_found');

    Queue::assertNotPushed(CreateJob::class);
});

it('enqueues through dispatchIfShouldSync on the accounting kind', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    [, , , , $payment] = accountingPaymentFixture($organization);

    CreateJob::dispatchIfShouldSync($payment);

    Queue::assertPushed(CreateJob::class);
});

it('does not enqueue through dispatchIfShouldSync without sync_payments', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    [, , $integration, , $payment] = accountingPaymentFixture($organization, [
        'settings' => ['sync_payments' => false],
    ]);

    CreateJob::dispatchIfShouldSync($payment);

    Queue::assertNotPushed(CreateJob::class);
});
