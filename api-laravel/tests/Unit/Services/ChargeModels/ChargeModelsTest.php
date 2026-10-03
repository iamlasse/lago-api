<?php

declare(strict_types=1);

use App\Enums\ChargeModel;
use App\Services\ChargeModels\Factory;
use App\Services\ChargeModels\PricingStructure;
use App\Services\ChargeModels\AggregationResult;

/**
 * Port of spec/services/charge_models/* (the 8 charge model amount
 * computations). Aggregations are the M1 value-object seam.
 */
function chargeModelPricing(ChargeModel $model, array $properties): PricingStructure
{
    return new PricingStructure(
        chargeModel: $model,
        properties: $properties,
        prorated: false,
        acceptsTargetWallet: false,
        currency: 'EUR',
    );
}

function applyChargeModel(ChargeModel $model, array $properties, AggregationResult $aggregation): App\Services\ChargeModels\ChargeModelResult
{
    return Factory::newInstance(
        pricingStructure: chargeModelPricing($model, $properties),
        aggregationResult: $aggregation,
    )->apply();
}

// -- standard -----------------------------------------------------------------

it('computes the standard charge model amount', function (): void {
    $result = applyChargeModel(ChargeModel::Standard, ['amount' => '2'], new AggregationResult(aggregation: '10.5', count: 3));

    expect((float) $result->amount)->toBe(21.0)
        ->and($result->units)->toBe('10.5')
        ->and((float) $result->unitAmount)->toBe(2.0)
        ->and($result->groupedResults)->toHaveCount(1);
});

// -- package ------------------------------------------------------------------

it('computes the package charge model with rounding up to full packages', function (): void {
    $result = applyChargeModel(ChargeModel::Package, [
        'amount' => '10',
        'free_units' => 5,
        'package_size' => 10,
    ], new AggregationResult(aggregation: '22', count: 22));

    // paid units = 17 → ceil(17/10) = 2 packages × 10 = 20
    expect((float) $result->amount)->toBe(20.0)
        ->and((float) $result->amountDetails['paid_units'])->toBe(17.0);
});

it('rounds a partial package UP, not half-up (Rails ceil)', function (): void {
    // Rails: paid_units.fdiv(per_package_size).ceil — a group counts from its
    // first unit. 111 paid units in packages of 10 bill 12 packages
    // (half-up rounding would bill 11 — the invoice_package golden bug).
    $result = applyChargeModel(ChargeModel::Package, [
        'amount' => '1000',
        'free_units' => 0,
        'package_size' => 10,
    ], new AggregationResult(aggregation: '111', count: 111));

    expect((float) $result->amount)->toBe(12000.0)
        ->and($result->amountDetails['per_package_size'])->toBe(10)
        ->and((float) $result->unitAmount)->toBe(12000.0 / 111.0);
});

it('bills an exact multiple of the package size without an extra package', function (): void {
    $result = applyChargeModel(ChargeModel::Package, [
        'amount' => '10',
        'free_units' => 0,
        'package_size' => 10,
    ], new AggregationResult(aggregation: '20', count: 20));

    expect((float) $result->amount)->toBe(20.0);
});

it('computes zero for package usage below the free units', function (): void {
    $result = applyChargeModel(ChargeModel::Package, [
        'amount' => '10',
        'free_units' => 5,
        'package_size' => 10,
    ], new AggregationResult(aggregation: '5', count: 5));

    expect((float) $result->amount)->toBe(0.0);
});

// -- graduated ----------------------------------------------------------------

it('computes the graduated charge model across ranges', function (): void {
    $result = applyChargeModel(ChargeModel::Graduated, [
        'graduated_ranges' => [
            ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '1', 'flat_amount' => '5'],
            ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '0.5', 'flat_amount' => '10'],
        ],
    ], new AggregationResult(aggregation: '15', count: 15));

    // first range: 10 units × 1 + 5 = 15; second: (15-11+1=5) × 0.5 + 10 = 12.5
    expect((float) $result->amount)->toBe(27.5)
        ->and($result->amountDetails['graduated_ranges'])->toHaveCount(2);
});

it('breaks graduated ranges at the last relevant one', function (): void {
    $result = applyChargeModel(ChargeModel::Graduated, [
        'graduated_ranges' => [
            ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '1', 'flat_amount' => '0'],
            ['from_value' => 11, 'to_value' => 20, 'per_unit_amount' => '2', 'flat_amount' => '0'],
            ['from_value' => 21, 'to_value' => null, 'per_unit_amount' => '4', 'flat_amount' => '0'],
        ],
    ], new AggregationResult(aggregation: '5', count: 5));

    expect((float) $result->amount)->toBe(5.0)
        ->and($result->amountDetails['graduated_ranges'])->toHaveCount(1);
});

// -- graduated_percentage -----------------------------------------------------

it('computes the graduated_percentage charge model', function (): void {
    $result = applyChargeModel(ChargeModel::GraduatedPercentage, [
        'graduated_percentage_ranges' => [
            ['from_value' => 0, 'to_value' => 10, 'rate' => '10', 'flat_amount' => '5'],
            ['from_value' => 11, 'to_value' => null, 'rate' => '5', 'flat_amount' => '2'],
        ],
    ], new AggregationResult(aggregation: '12', count: 12));

    // first: 10 × 10% = 1 + 5 = 6; second: (12-11+1=2) × 5% = 0.1 + 2 = 2.1
    expect((float) $result->amount)->toBe(8.1);
});

// -- percentage ---------------------------------------------------------------

it('computes the percentage charge model with rate and fixed fee', function (): void {
    $result = applyChargeModel(ChargeModel::Percentage, [
        'rate' => '10',
        'fixed_amount' => '2',
    ], new AggregationResult(aggregation: '1000', count: 5));

    // 1000 × 10% = 100 + 5 events × 2 = 110
    expect((float) $result->amount)->toBe(110.0)
        ->and($result->amountDetails['paid_events'])->toBe(5);
});

it('applies free events and free amount in the percentage model', function (): void {
    $result = applyChargeModel(ChargeModel::Percentage, [
        'rate' => '10',
        'fixed_amount' => '1',
        'free_units_per_events' => 2,
    ], new AggregationResult(aggregation: '1000', count: 5, options: ['running_total' => ['100', '200', '400', '800', '1000']]));

    // 3 paid events × 1 fixed = 3; percentage on 1000 - 200 (2 free events) = 80
    expect((float) $result->amount)->toBe(83.0);
});

it('replays the invoice_percentage golden math (fixed fee + event split)', function (): void {
    // The captured scenario: rate 1.3%, fixed_amount 2.0, free_units_per_events
    // 3, free_units_per_total_aggregation 250 — four events of 200 (running
    // total limited to the first 3 per Rails' running_total_per_events).
    // Rails golden: fee 1315c, free_events 1, paid_events 3,
    // fixed_fee_total_amount "6.0", per_unit_total_amount "7.15".
    $result = applyChargeModel(ChargeModel::Percentage, [
        'rate' => '1.3',
        'fixed_amount' => '2.0',
        'free_units_per_events' => 3,
        'free_units_per_total_aggregation' => '250.0',
    ], new AggregationResult(aggregation: '800', count: 4, options: ['running_total' => ['200', '400', '600']]));

    expect((float) $result->amount)->toBe(13.15)
        ->and($result->amountDetails['free_events'])->toBe(1)
        ->and($result->amountDetails['paid_events'])->toBe(3)
        ->and((float) $result->amountDetails['fixed_fee_total_amount'])->toBe(6.0)
        ->and((float) $result->amountDetails['per_unit_total_amount'])->toBe(7.15)
        ->and((float) $result->amountDetails['fixed_fee_unit_amount'])->toBe(2.0)
        ->and((float) $result->amountDetails['free_units'])->toBe(250.0);
});

// -- volume -------------------------------------------------------------------

it('computes the volume charge model picking a single tier', function (): void {
    $result = applyChargeModel(ChargeModel::Volume, [
        'volume_ranges' => [
            ['from_value' => 0, 'to_value' => 100, 'per_unit_amount' => '1', 'flat_amount' => '5'],
            ['from_value' => 101, 'to_value' => null, 'per_unit_amount' => '0.5', 'flat_amount' => '50'],
        ],
    ], new AggregationResult(aggregation: '150', count: 150));

    // tier 2: 150 × 0.5 + 50 = 125
    expect((float) $result->amount)->toBe(125.0);
});

// -- custom -------------------------------------------------------------------

it('computes the custom charge model from the custom aggregation amount', function (): void {
    $result = applyChargeModel(ChargeModel::Custom, [], new AggregationResult(
        aggregation: '5',
        count: 1,
        customAggregation: ['amount' => '42.5'],
    ));

    expect((float) $result->amount)->toBe(42.5)
        ->and((float) $result->unitAmount)->toBe(8.5);
});

// -- dynamic ------------------------------------------------------------------

it('computes the dynamic charge model from the precise event total', function (): void {
    $result = applyChargeModel(ChargeModel::Dynamic, [], new AggregationResult(
        aggregation: '10',
        fullUnitsNumber: '10',
        count: 10,
        preciseTotalAmountCents: '2500',
    ));

    expect((float) $result->amount)->toBe(25.0)
        ->and((float) $result->unitAmount)->toBe(2.5);
});

// -- grouped ------------------------------------------------------------------

it('applies grouped aggregations per group entry', function (): void {
    $result = Factory::newInstance(
        pricingStructure: new PricingStructure(
            chargeModel: ChargeModel::Standard,
            properties: ['amount' => '1'],
            prorated: false,
            acceptsTargetWallet: false,
            currency: 'EUR',
        ),
        aggregationResult: new AggregationResult(
            aggregations: [
                new AggregationResult(aggregation: '2', count: 1, groupedBy: ['region' => 'us']),
                new AggregationResult(aggregation: '3', count: 1, groupedBy: ['region' => 'eu']),
            ],
        ),
    )->apply();

    expect($result->groupedResults)->toHaveCount(2)
        ->and((float) $result->amount)->toBe(5.0)
        ->and($result->groupedResults[0]->groupedBy)->toBe(['region' => 'us']);
});

// -- factory routing ----------------------------------------------------------

it('routes each charge model to its service', function (): void {
    $pricing = fn (ChargeModel $m) => chargeModelPricing($m, ['amount' => '1']);

    expect(Factory::chargeModelClass($pricing(ChargeModel::Standard)))->toBe(App\Services\ChargeModels\StandardService::class)
        ->and(Factory::chargeModelClass($pricing(ChargeModel::Graduated)))->toBe(App\Services\ChargeModels\GraduatedService::class)
        ->and(Factory::chargeModelClass($pricing(ChargeModel::Package)))->toBe(App\Services\ChargeModels\PackageService::class)
        ->and(Factory::chargeModelClass($pricing(ChargeModel::Percentage)))->toBe(App\Services\ChargeModels\PercentageService::class)
        ->and(Factory::chargeModelClass($pricing(ChargeModel::Volume)))->toBe(App\Services\ChargeModels\VolumeService::class)
        ->and(Factory::chargeModelClass($pricing(ChargeModel::GraduatedPercentage)))->toBe(App\Services\ChargeModels\GraduatedPercentageService::class)
        ->and(Factory::chargeModelClass($pricing(ChargeModel::Custom)))->toBe(App\Services\ChargeModels\CustomService::class)
        ->and(Factory::chargeModelClass($pricing(ChargeModel::Dynamic)))->toBe(App\Services\ChargeModels\DynamicService::class);
});
