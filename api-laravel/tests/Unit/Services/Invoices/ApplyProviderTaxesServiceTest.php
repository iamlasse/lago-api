<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Fee;
use App\Models\FeeAppliedTax;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\Invoices\ApplyProviderTaxesService;
use App\Services\Integrations\Aggregator\Taxes\TaxBreakdownItem;
use App\Services\Integrations\Aggregator\Taxes\TaxResult;

/**
 * Port of Rails' spec/services/invoices/apply_provider_taxes_service_spec.rb
 * — the invoice-level tax rows over the per-fee provider answers.
 */
function invTaxInvoice(Organization $organization, Customer $customer, int $feesAmountCents = 3000, int $couponsAmountCents = 0): Invoice
{
    return Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'fees_amount_cents' => $feesAmountCents,
        'coupons_amount_cents' => $couponsAmountCents,
        'sub_total_excluding_taxes_amount_cents' => $feesAmountCents - $couponsAmountCents,
    ]);
}

function invTaxFee(Invoice $invoice, int $amountCents, int $preciseCouponsCents = 0): Fee
{
    return Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => $amountCents,
        'precise_amount_cents' => (string) $amountCents,
        'precise_coupons_amount_cents' => (string) $preciseCouponsCents,
        'amount_currency' => $invoice->currency,
        'taxes_base_rate' => 1,
    ]);
}

function invTaxFeeAppliedTax(Fee $fee, int $amountCents, string $name, string $code, float $rate, string $description): FeeAppliedTax
{
    return FeeAppliedTax::factory()->create([
        'fee_id' => $fee->id,
        'organization_id' => $fee->organization_id,
        'amount_cents' => $amountCents,
        'precise_amount_cents' => (string) $amountCents,
        'tax_name' => $name,
        'tax_code' => $code,
        'tax_rate' => $rate,
        'tax_description' => $description,
        'amount_currency' => $fee->amount_currency,
    ]);
}

/**
 * Two fee answers, both taxed "tax 1" at 10% and the second additionally
 * "tax 2" at 12% (the Rails fixture set).
 */
function invTaxFeeTaxes(): array
{
    return [
        new TaxResult('k1', 'id1', 'c1', 1000, 100, [
            new TaxBreakdownItem('tax 1', '0.10', 100, 'type1'),
        ]),
        new TaxResult('k2', 'id2', 'c2', 2000, 440, [
            new TaxBreakdownItem('tax 1', '0.10', 200, 'type1'),
            new TaxBreakdownItem('tax 2', '0.12', 240, 'type2'),
        ]),
    ];
}

it('creates the grouped invoice applied taxes', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = invTaxInvoice($organization, $customer);

    $fee1 = invTaxFee($invoice, 1000);
    $fee2 = invTaxFee($invoice, 2000);

    invTaxFeeAppliedTax($fee1, 100, 'tax 1', 'tax_1', 10.0, 'type1');
    invTaxFeeAppliedTax($fee2, 200, 'tax 1', 'tax_1', 10.0, 'type1');
    invTaxFeeAppliedTax($fee2, 240, 'tax 2', 'tax_2', 12.0, 'type2');

    $providerTaxes = invTaxFeeTaxes();

    $result = ApplyProviderTaxesService::call(invoice: $invoice, provider_taxes: $providerTaxes);

    expect($result->success())->toBeTrue()
        ->and(count($result->applied_taxes))->toBe(2);

    $tax1 = $result->applied_taxes[0];
    $tax2 = $result->applied_taxes[1];

    expect($tax1->tax_name)->toBe('tax 1')
        ->and($tax1->tax_code)->toBe('tax_1')
        ->and($tax1->tax_rate)->toBe(10.0)
        ->and($tax1->amount_cents)->toBe(300)
        ->and($tax1->fees_amount_cents)->toBe(3000)
        ->and($tax1->taxable_base_amount_cents)->toBe(3000)
        ->and($tax2->tax_name)->toBe('tax 2')
        ->and($tax2->tax_rate)->toBe(12.0)
        ->and($tax2->amount_cents)->toBe(240)
        ->and($tax2->fees_amount_cents)->toBe(2000);

    expect($invoice->taxes_amount_cents)->toBe(540)
        ->and((float) $invoice->taxes_rate)->toBe(18.0);
});

it('prorates the rate over the fees that carry a tax, not every fee', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = invTaxInvoice($organization, $customer, 1000, 1000);

    $reportedFee = invTaxFee($invoice, 1000, 1000);
    $untaxedFee = invTaxFee($invoice, 0);

    invTaxFeeAppliedTax($reportedFee, 0, 'tax 1', 'tax_1', 10.0, 'type1');

    $providerTaxes = [
        new TaxResult('k1', 'id1', 'c1', 1000, 0, [
            new TaxBreakdownItem('tax 1', '0.10', 0, 'type1'),
        ]),
    ];

    $result = ApplyProviderTaxesService::call(invoice: $invoice, provider_taxes: $providerTaxes);

    expect($result->success())->toBeTrue();

    expect((float) $invoice->taxes_rate)->toBe(10.0);
});

it('prorates the rate over the fee count when the invoice subtotal is zero', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = invTaxInvoice($organization, $customer, 1000, 1000);

    $fee1 = invTaxFee($invoice, 1000, 1000);
    $fee2 = invTaxFee($invoice, 0);

    invTaxFeeAppliedTax($fee1, 0, 'tax 1', 'tax_1', 10.0, 'type1');

    $providerTaxes = [
        new TaxResult('k1', 'id1', 'c1', 1000, 0, [
            new TaxBreakdownItem('tax 1', '0.10', 0, 'type1'),
        ]),
    ];

    $result = ApplyProviderTaxesService::call(invoice: $invoice, provider_taxes: $providerTaxes);

    expect($result->success())->toBeTrue();

    // The taxed fee carries the whole (zero-subtotal) rate: 10% x 1/1.
    expect((float) $invoice->taxes_rate)->toBe(10.0);
});
