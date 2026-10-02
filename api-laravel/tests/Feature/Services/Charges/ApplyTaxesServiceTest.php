<?php

declare(strict_types=1);

use App\Models\Tax;
use App\Models\Plan;
use App\Models\Charge;
use App\Models\ChargeTax;
use App\Models\BillableMetric;
use App\Services\Charges\ApplyTaxesService;

/**
 * Port of spec/services/charges/apply_taxes_service_spec.rb.
 */
function taxedCharge(): Charge
{
    $plan = Plan::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $plan->organization_id]);

    return Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'properties' => ['amount' => '10'],
    ]);
}

it('applies taxes to a charge by tax code', function () {
    $charge = taxedCharge();
    $vat = Tax::factory()->create(['organization_id' => $charge->organization_id, 'code' => 'vat-20']);

    $result = ApplyTaxesService::call(charge: $charge, taxCodes: ['vat-20']);

    expect($result->success())->toBeTrue()
        ->and($result->applied_taxes)->toHaveCount(1)
        ->and(ChargeTax::query()->where('charge_id', $charge->id)->where('tax_id', $vat->id)->exists())->toBeTrue();
})->group('ledger:svc:Charges.ApplyTaxesService');

it('replaces the applied taxes when the codes change', function () {
    $charge = taxedCharge();
    Tax::factory()->create(['organization_id' => $charge->organization_id, 'code' => 'vat-20']);
    $vat2 = Tax::factory()->create(['organization_id' => $charge->organization_id, 'code' => 'vat-5']);

    ApplyTaxesService::call(charge: $charge, taxCodes: ['vat-20']);
    $result = ApplyTaxesService::call(charge: $charge->refresh(), taxCodes: ['vat-5']);

    expect($result->success())->toBeTrue()
        ->and($charge->taxes()->pluck('code')->all())->toBe(['vat-5'])
        // destroy_all hard-deletes the pivot rows (charges_taxes has no
        // deleted_at column).
        ->and(ChargeTax::query()->where('charge_id', $charge->id)->where('tax_id', '!=', $vat2->id)->count())->toBe(0);
});

it('fails with an unknown tax code', function () {
    $charge = taxedCharge();

    $result = ApplyTaxesService::call(charge: $charge, taxCodes: ['unknown-tax']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('tax_not_found');
});

it('is idempotent for the same tax codes', function () {
    $charge = taxedCharge();
    Tax::factory()->create(['organization_id' => $charge->organization_id, 'code' => 'vat-20']);

    ApplyTaxesService::call(charge: $charge, taxCodes: ['vat-20']);
    $result = ApplyTaxesService::call(charge: $charge->refresh(), taxCodes: ['vat-20']);

    expect($result->success())->toBeTrue()
        ->and(ChargeTax::query()->where('charge_id', $charge->id)->count())->toBe(1);
});
