<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Organization;
use App\Services\Integrations\Aggregator\Taxes\TaxResult;
use App\Services\Integrations\Aggregator\Taxes\TaxBreakdownItem;
use App\Services\Integrations\Aggregator\Taxes\Invoices\ChargeFeeGroup;

/**
 * Port of Rails' spec/services/integrations/aggregator/taxes/invoices/
 * charge_fee_group_spec.rb — one line per charge, taxes split back over the
 * member fees pro rata to their taxable base.
 */
function cfgFee(Organization $organization, object $invoice, int $amountCents, int $couponCents = 0): Fee
{
    return Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'fee_type' => App\Enums\FeeType::Charge,
        'amount_cents' => $amountCents,
        'precise_amount_cents' => (string) $amountCents,
        'precise_coupons_amount_cents' => (string) $couponCents,
        'units' => '1',
        'amount_currency' => 'EUR',
    ]);
}

it('groups fees by charge, preserving order and unrelated fees', function (): void {
    $organization = Organization::factory()->create();
    $invoice = App\Models\Invoice::factory()->create(['organization_id' => $organization->id]);

    $fee1 = cfgFee($organization, $invoice, 300);
    $addOnFee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
        'fee_type' => App\Enums\FeeType::AddOn,
        'amount_cents' => 500,
    ]);
    $fee2 = cfgFee($organization, $invoice, 700);

    $lineItems = ChargeFeeGroup::build([$fee1, $addOnFee, $fee2]);

    expect(count($lineItems))->toBe(2)
        ->and($lineItems[0])->toBeInstanceOf(ChargeFeeGroup::class)
        ->and($lineItems[0]->fees)->toBe([$fee1, $fee2])
        ->and($lineItems[1])->toBeInstanceOf(Fee::class)
        ->and($lineItems[1]->id)->toBe($addOnFee->id);
});

it('sums amounts and sub totals over the group payload interface', function (): void {
    $organization = Organization::factory()->create();
    $invoice = App\Models\Invoice::factory()->create(['organization_id' => $organization->id]);

    $fee1 = cfgFee($organization, $invoice, 300);
    $fee2 = cfgFee($organization, $invoice, 700, 200);

    $group = new ChargeFeeGroup(fees: [$fee1, $fee2]);

    expect($group->id())->toBeNull()
        ->and($group->itemKey())->toBe($fee1->charge_id)
        ->and($group->itemId())->toBe($fee1->charge_id)
        ->and($group->isCharge())->toBeTrue()
        ->and($group->amountCents())->toBe(1000)
        ->and($group->subTotalExcludingTaxesAmountCents())->toBe(800);
});

it('splits the group taxes pro rata over the member fees', function (): void {
    $organization = Organization::factory()->create();
    $invoice = App\Models\Invoice::factory()->create(['organization_id' => $organization->id]);

    $fee1 = cfgFee($organization, $invoice, 300);
    $fee2 = cfgFee($organization, $invoice, 700);

    $group = new ChargeFeeGroup(fees: [$fee1, $fee2]);

    $groupTaxes = new TaxResult(
        itemKey: $fee1->charge_id,
        itemId: $fee1->charge_id,
        itemCode: 'metric_code',
        amountCents: 1000,
        taxAmountCents: 100,
        taxBreakdown: [new TaxBreakdownItem('VAT', '0.10', 100, 'tax')],
    );

    $feeTaxes = $group->splitTaxes($groupTaxes);

    expect(array_map(fn ($item) => $item->itemKey, $feeTaxes))->toBe([$fee1->itemKey(), $fee2->itemKey()])
        ->and(array_map(fn ($item) => $item->itemId, $feeTaxes))->toBe([$fee1->id, $fee2->id])
        ->and(array_map(fn ($item) => $item->itemCode, $feeTaxes))->toBe(['metric_code', 'metric_code'])
        ->and(array_map(fn ($item) => $item->amountCents, $feeTaxes))->toBe([300, 700])
        ->and(array_map(fn ($item) => $item->taxAmountCents, $feeTaxes))->toBe([30, 70])
        ->and(array_map(fn ($item) => $item->taxBreakdown[0]->rate, $feeTaxes))->toBe(['0.10', '0.10']);
});

it('allocates whole cents without losing the unrounded shares', function (): void {
    $organization = Organization::factory()->create();
    $invoice = App\Models\Invoice::factory()->create(['organization_id' => $organization->id]);

    $fee1 = cfgFee($organization, $invoice, 300);
    $fee2 = cfgFee($organization, $invoice, 700);

    $group = new ChargeFeeGroup(fees: [$fee1, $fee2]);

    $groupTaxes = new TaxResult(
        itemKey: $fee1->charge_id,
        itemId: null,
        itemCode: 'metric_code',
        amountCents: 1000,
        taxAmountCents: 7,
        taxBreakdown: [new TaxBreakdownItem('VAT', '0.10', 7, 'tax')],
    );

    $feeTaxes = $group->splitTaxes($groupTaxes);

    expect(array_map(fn ($item) => $item->taxBreakdown[0]->allocatedAmountCents, $feeTaxes))->toBe([2, 5])
        ->and(array_map(fn ($item) => (float) $item->taxBreakdown[0]->taxAmount, $feeTaxes))
        ->toBe([2.1, 4.9]);
});

it('keeps a zero-tax result for every fee when the breakdown is empty', function (): void {
    $organization = Organization::factory()->create();
    $invoice = App\Models\Invoice::factory()->create(['organization_id' => $organization->id]);

    $fee1 = cfgFee($organization, $invoice, 300);
    $fee2 = cfgFee($organization, $invoice, 700);

    $group = new ChargeFeeGroup(fees: [$fee1, $fee2]);

    $groupTaxes = new TaxResult(
        itemKey: $fee1->charge_id,
        itemId: null,
        itemCode: null,
        amountCents: 1000,
        taxAmountCents: 0,
        taxBreakdown: [],
    );

    $feeTaxes = $group->splitTaxes($groupTaxes);

    expect(array_map(fn ($item) => $item->itemId, $feeTaxes))->toBe([$fee1->id, $fee2->id])
        ->and(array_map(fn ($item) => $item->taxBreakdown, $feeTaxes))->toBe([[], []])
        ->and(array_map(fn ($item) => $item->taxAmountCents, $feeTaxes))->toBe([0, 0]);
});
