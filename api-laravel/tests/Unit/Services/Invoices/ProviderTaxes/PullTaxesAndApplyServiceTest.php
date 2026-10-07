<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use Illuminate\Support\Env;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Models\FeeAppliedTax;
use App\Enums\InvoiceTaxStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Models\Integrations\AnrokIntegration;
use App\Models\IntegrationCustomers\AnrokCustomer;
use App\Services\Invoices\ProviderTaxes\PullTaxesAndApplyService;

/**
 * Port of Rails' spec/services/invoices/provider_taxes/
 * pull_taxes_and_apply_service_spec.rb — the async leg of provider
 * taxation, over a stubbed Nango tax request.
 */
function pullTaxSetup(?string $invoiceStatus = null): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => 'ext-1',
    ]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => $invoiceStatus ?? InvoiceStatus::Pending,
        'tax_status' => InvoiceTaxStatus::Pending->value,
        'fees_amount_cents' => 1000,
        'coupons_amount_cents' => 0,
        'sub_total_excluding_taxes_amount_cents' => 1000,
        'sub_total_including_taxes_amount_cents' => 1000,
        'total_amount_cents' => 1000,
        'currency' => 'EUR',
    ]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => 1000,
        'precise_amount_cents' => '1000',
        'precise_coupons_amount_cents' => '0',
        'amount_currency' => 'EUR',
    ]);

    FeeAppliedTax::factory()->create([
        'fee_id' => $fee->id,
        'organization_id' => $organization->id,
        'amount_cents' => 100,
        'precise_amount_cents' => '100',
        'tax_name' => 'GST/HST',
        'tax_code' => 'gst_hst',
        'tax_rate' => 10.0,
        'tax_description' => 'tax',
        'amount_currency' => 'EUR',
    ]);

    return [$organization, $customer, $integration, $invoice, $fee];
}

function pullTaxSuccessBody(): array
{
    return [
        'succeededInvoices' => [[
            'id' => 'inv_1234567890',
            'issuing_date' => '2024-03-07',
            'currency' => 'EUR',
            'fees' => [[
                'item_id' => 'ignored',
                'item_code' => 'lago_default_b2b',
                'amount_cents' => 1000,
                'tax_amount_cents' => 100,
                'tax_breakdown' => [
                    ['name' => 'GST/HST', 'rate' => '0.10', 'tax_amount' => 100, 'type' => 'tax'],
                ],
            ]],
        ]],
        'failedInvoices' => [],
    ];
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

it('returns not found without an invoice', function (): void {
    $result = PullTaxesAndApplyService::call(invoice: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('invoice_not_found');
});

it('returns not found when the customer has no tax connection', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    Http::fake();

    $result = PullTaxesAndApplyService::call(invoice: $invoice);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('integration_customer_not_found');

    Http::assertNothingSent();
});

it('returns untouched when the invoice tax status is not pending', function (): void {
    [, , , $invoice] = pullTaxSetup();

    $invoice->update(['tax_status' => InvoiceTaxStatus::Succeeded->value]);

    Http::fake();

    $result = PullTaxesAndApplyService::call(invoice: $invoice->refresh());

    expect($result->success())->toBeTrue();

    Http::assertNothingSent();
});

it('pulls the provider taxes and finalizes a pending invoice', function (): void {
    Queue::fake();

    [, , , $invoice, $fee] = pullTaxSetup();

    Http::fake([
        'https://api.nango.dev/v1/anrok/finalized_invoices' => Http::response(pullTaxSuccessBody()),
    ]);

    $result = PullTaxesAndApplyService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();

    $invoice = $invoice->refresh();

    expect($invoice->tax_status)->toBe(InvoiceTaxStatus::Succeeded->value)
        ->and($invoice->status)->toBe(InvoiceStatus::Finalized)
        ->and($invoice->taxes_amount_cents)->toBe(100)
        ->and($invoice->total_amount_cents)->toBe(1100)
        ->and($invoice->appliedTaxes()->count())->toBe(1);

    // Post-finalize emissions.
    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'invoice.created');
    Queue::assertPushed(App\Jobs\Invoices\GenerateDocumentsJob::class);
});

it('marks the invoice failed and keeps the draft when the provider taxes fail', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);
    AnrokCustomer::factory()->create([
        'organization_id' => $organization->id,
        'integration_id' => $integration->id,
        'customer_id' => $customer->id,
        'external_customer_id' => 'ext-1',
    ]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Draft,
        'tax_status' => InvoiceTaxStatus::Pending->value,
        'fees_amount_cents' => 1000,
        'coupons_amount_cents' => 0,
        'sub_total_excluding_taxes_amount_cents' => 1000,
        'sub_total_including_taxes_amount_cents' => 1000,
        'total_amount_cents' => 1000,
    ]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => 1000,
        'precise_amount_cents' => '1000',
        'amount_currency' => 'EUR',
    ]);

    FeeAppliedTax::factory()->create([
        'fee_id' => $fee->id,
        'organization_id' => $organization->id,
        'tax_name' => 'GST/HST',
        'tax_code' => 'gst_hst',
        'tax_rate' => 10.0,
        'tax_description' => 'tax',
        'amount_currency' => 'EUR',
    ]);

    App\Models\WebhookEndpoint::factory()->create(['organization_id' => $organization->id]);

    Http::fake([
        'https://api.nango.dev/v1/anrok/draft_invoices' => Http::response([
            'succeededInvoices' => [],
            'failedInvoices' => [['validation_errors' => ['type' => 'taxDateTooFarInFuture']]],
        ]),
    ]);

    $result = PullTaxesAndApplyService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();

    $invoice = $invoice->refresh();

    expect($invoice->tax_status)->toBe(InvoiceTaxStatus::Failed->value)
        // A draft keeps its status and only announces ready_to_finalize.
        ->and($invoice->status)->toBe(InvoiceStatus::Draft);

    Queue::assertPushed(App\Jobs\SendWebhookJob::class, fn ($job) => $job->webhookType === 'invoice.ready_to_finalize');
});
