<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\AddOn;
use App\Models\Invoice;
use App\Models\Customer;
use Illuminate\Support\Env;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Models\Integrations\AnrokIntegration;
use App\Models\Integrations\AvalaraIntegration;
use App\Models\IntegrationCustomers\AnrokCustomer;
use App\Models\IntegrationCustomers\AvalaraCustomer;
use App\Services\Integrations\Aggregator\BadGatewayError;
use App\Services\Integrations\Aggregator\OutOfMemoryError;
use App\Services\Integrations\Aggregator\ServerContentionError;
use App\Services\Integrations\Aggregator\Taxes\Invoices\CreateService;

/**
 * Port of Rails' spec/services/integrations/aggregator/taxes/invoices/
 * create_service_spec.rb — the Nango finalized_invoices tax request for the
 * Anrok and Avalara providers, over Http::fake.
 */
function taxInvoiceFixture(Organization $organization): array
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'cus_lago_12345',
    ]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);
    $addOn = AddOn::factory()->create(['organization_id' => $organization->id]);
    $addOnTwo = AddOn::factory()->create(['organization_id' => $organization->id]);

    $feeAddOn = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'add_on_id' => $addOn->id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => 200,
        'precise_amount_cents' => '200',
        'created_at' => now()->subSeconds(3),
    ]);
    $feeAddOnTwo = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'add_on_id' => $addOnTwo->id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => 200,
        'precise_amount_cents' => '200',
        'created_at' => now()->subSeconds(2),
    ]);

    return [$customer, $invoice, $feeAddOn, $feeAddOnTwo];
}

function taxSuccessResponse(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/IntegrationAggregator/taxes/invoices/success_response.json'));
}

function taxFailureResponse(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/IntegrationAggregator/taxes/invoices/failure_response.json'));
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

it('returns the provider fees for an anrok invoice', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $feeAddOn, $feeAddOnTwo] = taxInvoiceFixture($organization);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => null,
    ]);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/v1/anrok/finalized_invoices') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response(taxSuccessResponse());
    });

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();

    $fees = $result->fees;

    expect($fees)->toHaveCount(1)
        ->and($fees[0]->taxBreakdown[0]->rate)->toBe('0.10')
        ->and($fees[0]->taxBreakdown[0]->name)->toBe('GST/HST')
        ->and($fees[0]->taxBreakdown[1]->name)->toBe('Reverse charge')
        ->and($fees[0]->taxBreakdown[1]->type)->toBe('exempt')
        ->and($fees[0]->taxBreakdown[1]->rate)->toBe('0.00');

    // The request carried the anrok headers and the fee/tax shapes.
    expect($captured->header('Provider-Config-Key'))->toBe(['anrok'])
        ->and($captured->header('Authorization'))->toBe(['Bearer secret']);

    $body = $captured->data();
    $contact = $body[0]['contact'];

    expect($body[0]['id'])->toBe($invoice->id)
        ->and($contact['external_id'])->toBe('cus_lago_12345')
        ->and($body[0]['fees'])->toHaveCount(2)
        ->and($body[0]['fees'][0]['item_id'])->toBe($feeAddOn->id)
        ->and($body[0]['fees'][0]['amount_cents'])->toBe(200)
        ->and($body[0]['tax_date'])->toEqual($body[0]['issuing_date']);

    // First sync stamps the integration customer external id.
    expect($customer->taxCustomer()->refresh()->external_customer_id)->toBe('cus_lago_12345');
});

it('returns the provider fees for an avalara invoice and stores the resource', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $feeAddOn, $feeAddOnTwo] = taxInvoiceFixture($organization);

    $integration = AvalaraIntegration::factory()->create(['organization_id' => $organization->id]);
    AvalaraCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => '123',
    ]);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        if ($request->url() !== 'https://api.nango.dev/v1/avalara/finalized_invoices') {
            return Http::response('', 500);
        }

        $captured = $request;

        return Http::response(taxSuccessResponse());
    });

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($invoice->integrationResources()->count())->toBe(1);

    $body = $captured->data();

    expect($captured->header('Provider-Config-Key'))->toBe(['avalara-sandbox'])
        ->and($body[0]['type'])->toBe('salesInvoice')
        ->and($body[0]['contact']['external_id'])->toBe('123')
        ->and($body[0]['contact']['region'])->toBe($customer->state)
        ->and($body[0]['billing_entity'])->toBeArray()
        ->and($body[0]['fees'][0]['item_id'])->toBe($feeAddOn->id)
        ->and($body[0]['fees'][0]['amount'])->toBe('2.0')
        ->and($body[0]['fees'][0]['unit'])->toBe('1');
});

it('sends a return invoice for a voided avalara invoice', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $feeAddOn] = taxInvoiceFixture($organization);

    $invoice->update(['status' => App\Enums\InvoiceStatus::Voided]);

    $integration = AvalaraIntegration::factory()->create(['organization_id' => $organization->id]);
    AvalaraCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => '123',
    ]);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        $captured = $request;

        return Http::response(taxSuccessResponse());
    });

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();

    $body = $captured->data();

    expect($body[0]['type'])->toBe('returnInvoice')
        ->and($body[0]['fees'][0]['amount'])->toBe('-2.0');
});

it('excludes a fee with no amount from the request', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice, $feeAddOn, $feeAddOnTwo] = taxInvoiceFixture($organization);

    $feeAddOnTwo->update(['amount_cents' => 0, 'precise_amount_cents' => '0']);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => 'already-synced',
    ]);

    $captured = null;
    Http::fake(function ($request) use (&$captured) {
        $captured = $request;

        return Http::response(taxSuccessResponse());
    });

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();

    $body = $captured->data();

    expect($body[0]['fees'])->toHaveCount(1)
        ->and($body[0]['fees'][0]['item_id'])->toBe($feeAddOn->id);
});

it('fails with the provider validation code and delivers the tax error webhook', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    [$customer, $invoice] = taxInvoiceFixture($organization);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => null,
    ]);

    WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    Http::fake([
        'https://api.nango.dev/v1/anrok/finalized_invoices' => Http::response(taxFailureResponse()),
    ]);

    $result = CreateService::call(invoice: $invoice);

    expect($result->failure())->toBeTrue()
        ->and($result->fees)->toBeNull()
        ->and($result->getError()->code)->toBe('taxDateTooFarInFuture');

    // The external customer id was not stamped on failure.
    expect($customer->taxCustomer()->refresh()->external_customer_id)->toBeNull();

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job): bool => $job->webhookType === 'customer.tax_provider_error'
        && $job->options['provider'] === 'anrok'
        && $job->options['provider_code'] === $integration->code
        && $job->options['provider_error']['error_code'] === 'taxDateTooFarInFuture');
});

it('raises the bad gateway error for a 502 body', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = taxInvoiceFixture($organization);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
    ]);

    Http::fake([
        'https://api.nango.dev/v1/anrok/finalized_invoices' => Http::response('<html>502 Bad Gateway</html>', 502),
    ]);

    CreateService::call(invoice: $invoice);
})->throws(BadGatewayError::class);

it('raises the out of memory error from the failed invoice body', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = taxInvoiceFixture($organization);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
    ]);

    Http::fake([
        'https://api.nango.dev/v1/anrok/finalized_invoices' => Http::response([
            'succeededInvoices' => [],
            'failedInvoices' => [['validation_errors' => 'function_runtime_out_of_memory']],
        ]),
    ]);

    CreateService::call(invoice: $invoice);
})->throws(OutOfMemoryError::class);

it('raises the server contention error from the failed invoice body', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = taxInvoiceFixture($organization);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
    ]);

    Http::fake([
        'https://api.nango.dev/v1/anrok/finalized_invoices' => Http::response([
            'succeededInvoices' => [],
            'failedInvoices' => [['validation_errors' => 'API limit exceeded']],
        ]),
    ]);

    CreateService::call(invoice: $invoice);
})->throws(ServerContentionError::class);

it('returns untouched when the customer has no tax integration customer', function (): void {
    $organization = Organization::factory()->create();
    [$customer, $invoice] = taxInvoiceFixture($organization);

    Http::fake();

    $result = CreateService::call(invoice: $invoice);

    expect($result->success())->toBeTrue()
        ->and($result->fees)->toBeNull();

    Http::assertNothingSent();
});
