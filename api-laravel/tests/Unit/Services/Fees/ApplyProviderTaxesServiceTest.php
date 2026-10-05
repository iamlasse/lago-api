<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Fee;
use App\Models\FeeAppliedTax;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\Fees\ApplyProviderTaxesService;
use App\Services\Integrations\Aggregator\Taxes\TaxBreakdownItem;
use App\Services\Integrations\Aggregator\Taxes\TaxResult;

/**
 * Port of Rails' spec/services/fees/apply_provider_taxes_service_spec.rb.
 */
function taxResult(int|float|null $taxAmountCents, array $breakdown): TaxResult
{
    return new TaxResult(
        itemKey: 'key',
        itemId: 'id',
        itemCode: 'code',
        amountCents: 1000,
        taxAmountCents: $taxAmountCents,
        taxBreakdown: $breakdown,
    );
}

function taxBreakdownItem(string $name, string $type, string $rate, int|float $taxAmount): TaxBreakdownItem
{
    return new TaxBreakdownItem(name: $name, rate: $rate, taxAmount: $taxAmount, type: $type);
}

function providerTaxFee(Organization $organization, Invoice $invoice, int $amountCents, bool $persisted = true): Fee
{
    $attributes = [
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => $amountCents,
        'precise_amount_cents' => (string) $amountCents,
        'precise_coupons_amount_cents' => '0',
        'taxes_amount_cents' => 0,
        'taxes_precise_amount_cents' => '0',
        'taxes_rate' => 0,
        'taxes_base_rate' => 0.0,
        'amount_currency' => 'EUR',
    ];

    return $persisted ? Fee::factory()->create($attributes) : Fee::factory()->make($attributes);
}

it('stores the provider allocation in both tax amount columns', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 1000);
    $feeTaxes = taxResult(146, [
        taxBreakdownItem('State tax', 'tax', '0.12', 96),
        taxBreakdownItem('City tax', 'tax', '0.05', 50),
    ]);

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: $feeTaxes);

    expect($result->success())->toBeTrue()
        ->and($result->applied_taxes[0]->amount_cents)->toBe(96)
        ->and($result->applied_taxes[1]->amount_cents)->toBe(50)
        ->and((float) $result->applied_taxes[0]->precise_amount_cents)->toBe(96.0)
        ->and((float) $result->applied_taxes[1]->precise_amount_cents)->toBe(50.0);

    expect($fee->taxes_amount_cents)->toBe(146);
});

it('preserves fractional cents separately from the booked allocation', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 1000);
    $feeTaxes = taxResult(8, array_map(
        fn (string $name) => taxBreakdownItem($name, 'tax', '0.025', 2.5),
        ['State', 'County', 'City'],
    ));

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: $feeTaxes);

    expect(array_map(fn ($tax) => $tax->amount_cents, $result->applied_taxes))->toBe([3, 3, 2])
        ->and(array_map(fn ($tax) => (float) $tax->precise_amount_cents, $result->applied_taxes))
        ->toBe([2.5, 2.5, 2.5]);

    expect($fee->taxes_amount_cents)->toBe(8)
        ->and((float) $fee->taxes_precise_amount_cents)->toBe(7.5);
});

it('creates applied taxes based on the provider taxes', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 1000);
    $feeTaxes = taxResult(170, [
        taxBreakdownItem('tax 2', 'type2', '0.12', 120),
        taxBreakdownItem('tax 3', 'type3', '0.05', 50),
    ]);

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: $feeTaxes);

    expect($result->success())->toBeTrue()
        ->and(count($result->applied_taxes))->toBe(2);

    $codes = array_map(fn ($tax) => $tax->tax_code, $result->applied_taxes);

    expect($codes)->toEqualCanonicalizing(['tax_2', 'tax_3']);

    expect($fee->taxes_amount_cents)->toBe(170)
        ->and((float) $fee->taxes_precise_amount_cents)->toBe(170.0)
        ->and($fee->taxes_rate)->toBe(17.0);
});

it('stores the reduced taxable base rate', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 1000);
    $feeTaxes = taxResult(136, [
        taxBreakdownItem('tax 2', 'type2', '0.12', 96),
        taxBreakdownItem('tax 3', 'type3', '0.05', 40),
    ]);

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: $feeTaxes);

    expect($result->success())->toBeTrue();

    expect($fee->taxes_amount_cents)->toBe(136)
        ->and($fee->taxes_rate)->toBe(17.0)
        // 136 booked over the 170 the full rate would have produced.
        ->and(round((float) $fee->taxes_base_rate, 5))->toBe(0.8);
});

it('fails rather than leaving a fee with an amount untaxed', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 1000);

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('fee_missing_from_tax_response');

    expect($fee->appliedTaxes()->count())->toBe(0);
});

it('leaves a fee with no amount untaxed', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 0);

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: null);

    expect($result->success())->toBeTrue()
        ->and($result->applied_taxes)->toBe([])
        ->and($fee->appliedTaxes()->count())->toBe(0);
});

it('does not re-apply taxes when the fee already has them', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 1000);
    FeeAppliedTax::factory()->create(['fee_id' => $fee->id, 'organization_id' => $organization->id]);

    $feeTaxes = taxResult(170, [
        taxBreakdownItem('tax 2', 'type2', '0.12', 120),
        taxBreakdownItem('tax 3', 'type3', '0.05', 50),
    ]);

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: $feeTaxes);

    expect($result->success())->toBeTrue()
        ->and($result->applied_taxes)->toBe([])
        ->and($fee->appliedTaxes()->count())->toBe(1);
});

it('maps the special provider taxation rules to zero-rate applied taxes', function (string $type, string $name, string $code): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $fee = providerTaxFee($organization, $invoice, 1000);
    $feeTaxes = taxResult(0, [taxBreakdownItem($name, $type, '0.00', 0)]);

    $result = ApplyProviderTaxesService::call(fee: $fee, fee_taxes: $feeTaxes);

    expect($result->success())->toBeTrue()
        ->and(count($result->applied_taxes))->toBe(1);

    $appliedTax = $result->applied_taxes[0];

    expect($appliedTax->tax_code)->toBe($code)
        ->and($appliedTax->tax_name)->toBe($name)
        ->and($appliedTax->tax_description)->toBe($type);

    expect($fee->taxes_amount_cents)->toBe(0);
})->with([
    ['notCollecting', 'Not collecting', 'not_collecting'],
    ['productNotTaxed', 'Product not taxed', 'product_not_taxed'],
    ['jurisNotTaxed', 'Juris not taxed', 'juris_not_taxed'],
]);
