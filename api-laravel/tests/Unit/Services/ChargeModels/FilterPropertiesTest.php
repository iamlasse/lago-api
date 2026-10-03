<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\FixedCharge;
use App\Models\BillableMetric;
use App\Services\ChargeModels\FilterPropertiesService;
use App\Services\ChargeModels\BuildDefaultPropertiesService;

/**
 * Ports of spec/services/charge_models/filter_properties_service_spec.rb,
 * filter_properties/{charge,fixed_charge}_service_spec.rb and
 * build_default_properties_service_spec.rb — slicing the incoming properties
 * hash down to what each charge model accepts.
 */
function filterPropertiesMetric(int $aggregationType = 1): BillableMetric
{
    return BillableMetric::factory()->create([
        'organization_id' => App\Models\Organization::factory()->create()->id,
        'aggregation_type' => $aggregationType,
    ]);
}

function filterPropertiesCharge(string $chargeModel, ?BillableMetric $metric = null): Charge
{
    $metric ??= filterPropertiesMetric();

    return Charge::factory()->create([
        'plan_id' => App\Models\Plan::factory()->create(['organization_id' => $metric->organization_id])->id,
        'organization_id' => $metric->organization_id,
        'billable_metric_id' => $metric->id,
        'charge_model' => $chargeModel,
    ]);
}

it('delegates a charge to the charge filter service and slices properties', function (): void {
    $charge = filterPropertiesCharge('standard');

    $result = FilterPropertiesService::call(chargeable: $charge, properties: [
        'amount' => 100,
        'unknown_key' => 'dropped',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->properties)->toBe(['amount' => 100]);
})->group('ledger:svc:ChargeModels.FilterPropertiesService', 'ledger:svc:ChargeModels.FilterProperties.BaseService');

it('delegates a fixed charge to the fixed charge filter service', function (): void {
    $plan = App\Models\Plan::factory()->create();
    $fixedCharge = FixedCharge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'charge_model' => 'standard',
    ]);

    $result = FilterPropertiesService::call(chargeable: $fixedCharge, properties: [
        'amount' => '10',
        'unknown_key' => 'dropped',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->properties)->toBe(['amount' => '10']);
})->group('ledger:svc:ChargeModels.FilterProperties.FixedChargeService');

it('slices the graduated ranges', function (): void {
    $charge = filterPropertiesCharge('graduated');
    $ranges = [['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '1', 'flat_amount' => '0']];

    $result = FilterPropertiesService::call(chargeable: $charge, properties: [
        'graduated_ranges' => $ranges,
        'amount' => 'dropped',
    ]);

    expect($result->properties)->toBe(['graduated_ranges' => $ranges]);
})->group('ledger:svc:ChargeModels.FilterProperties.ChargeService');

it('slices the package and percentage attributes', function (): void {
    $package = FilterPropertiesService::call(chargeable: filterPropertiesCharge('package'), properties: [
        'amount' => '5', 'free_units' => 10, 'package_size' => 100, 'rate' => 'dropped',
    ]);
    $percentage = FilterPropertiesService::call(chargeable: filterPropertiesCharge('percentage'), properties: [
        'rate' => '1.5',
        'fixed_amount' => '0',
        'free_units_per_events' => 3,
        'free_units_per_total_aggregation' => '100',
        'per_transaction_min_amount' => '0',
        'per_transaction_max_amount' => '10',
        'amount' => 'dropped',
    ]);

    expect($package->properties)->toBe(['amount' => '5', 'free_units' => 10, 'package_size' => 100])
        // array_intersect_key preserves the INPUT key order.
        ->and($percentage->properties)->toBe([
            'rate' => '1.5',
            'fixed_amount' => '0',
            'free_units_per_events' => 3,
            'free_units_per_total_aggregation' => '100',
            'per_transaction_min_amount' => '0',
            'per_transaction_max_amount' => '10',
        ]);
})->group('ledger:svc:ChargeModels.FilterProperties.ChargeService');

it('slices the graduated percentage ranges', function (): void {
    $charge = filterPropertiesCharge('graduated_percentage');
    $ranges = [['from_value' => 0, 'to_value' => null, 'rate' => '10', 'fixed_amount' => '0', 'flat_amount' => '5']];

    $result = FilterPropertiesService::call(chargeable: $charge, properties: [
        'graduated_percentage_ranges' => $ranges,
    ]);

    expect($result->properties)->toBe(['graduated_percentage_ranges' => $ranges]);
})->group('ledger:svc:ChargeModels.FilterProperties.ChargeService');

it('keeps custom properties for a custom-aggregation metric and decodes JSON strings', function (): void {
    $metric = filterPropertiesMetric(7); // custom_agg
    $charge = filterPropertiesCharge('standard', $metric);

    $result = FilterPropertiesService::call(chargeable: $charge, properties: [
        'custom_properties' => '{"sum" : "amount"}',
        'amount' => '1',
    ]);

    expect($result->properties)->toBe([
        'custom_properties' => ['sum' => 'amount'],
        'amount' => '1',
    ]);
})->group('ledger:svc:ChargeModels.FilterProperties.ChargeService');

it('maps the deprecated grouped_by onto pricing_group_keys', function (): void {
    $charge = filterPropertiesCharge('graduated');

    $result = FilterPropertiesService::call(chargeable: $charge, properties: [
        'graduated_ranges' => [],
        'grouped_by' => ['region', null, ''],
    ]);

    expect($result->properties)->toBe([
        'graduated_ranges' => [],
        'pricing_group_keys' => ['region'],
    ]);
})->group('ledger:svc:ChargeModels.FilterProperties.BaseService');

it('keeps explicit pricing_group_keys without a grouped_by mirror', function (): void {
    $charge = filterPropertiesCharge('standard');

    $result = FilterPropertiesService::call(chargeable: $charge, properties: [
        'amount' => '1',
        'pricing_group_keys' => ['plan'],
    ]);

    expect($result->properties)->toBe([
        'amount' => '1',
        'pricing_group_keys' => ['plan'],
    ]);
})->group('ledger:svc:ChargeModels.FilterProperties.BaseService');

it('builds the default properties per charge model', function (string|int $chargeModel, array $expected): void {
    expect(BuildDefaultPropertiesService::call(chargeModel: $chargeModel)->properties)
        ->toBe($expected);
})->with([
    'standard' => ['standard', ['amount' => '0']],
    'graduated' => ['graduated', ['graduated_ranges' => [
        ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '0'],
    ]]],
    'package' => ['package', ['package_size' => 1, 'amount' => '0', 'free_units' => 0]],
    'percentage' => ['percentage', ['rate' => '0']],
    'volume' => ['volume', ['volume_ranges' => [
        ['from_value' => 0, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '0'],
    ]]],
    'graduated_percentage' => ['graduated_percentage', ['graduated_percentage_ranges' => [
        ['from_value' => 0, 'to_value' => null, 'rate' => '0', 'fixed_amount' => '0', 'flat_amount' => '0'],
    ]]],
    'dynamic' => ['dynamic', []],
    // Integer (frozen enum position) spellings resolve to the same defaults.
    'standard as int' => [0, ['amount' => '0']],
    'percentage as int' => [3, ['rate' => '0']],
])->group('ledger:svc:ChargeModels.BuildDefaultPropertiesService');
