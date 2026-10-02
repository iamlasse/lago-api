<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\ChargeFilter;
use App\Models\BillableMetric;
use App\Models\ChargeFilterValue;
use App\Models\BillableMetricFilter;
use App\Services\ChargeFilters\CreateOrUpdateBatchService;

/**
 * Port of spec/services/charge_filters/create_or_update_batch_service_spec.rb
 * (core scenarios).
 */
function filterCharge(): Charge
{
    $plan = App\Models\Plan::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $plan->organization_id]);

    BillableMetricFilter::query()->create([
        'billable_metric_id' => $metric->id,
        'organization_id' => $plan->organization_id,
        'key' => 'region',
        'values' => ['us', 'eu'],
    ]);
    BillableMetricFilter::query()->create([
        'billable_metric_id' => $metric->id,
        'organization_id' => $plan->organization_id,
        'key' => 'tier',
        'values' => ['gold', 'silver'],
    ]);

    return Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metric->id,
        'charge_model' => 'standard',
        'code' => 'std',
        'properties' => ['amount' => '10'],
    ]);
}

it('creates filters with generated codes', function (): void {
    $charge = filterCharge();

    $result = CreateOrUpdateBatchService::call(charge: $charge, filtersParams: [
        ['values' => ['region' => ['us']]],
        ['values' => ['region' => ['eu']]],
    ]);

    expect($result->success())->toBeTrue();

    $filters = $result->filters;

    expect($filters)->toHaveCount(2)
        ->and($filters[0]->code)->not->toBe($filters[1]->code)
        ->and($filters[0]->toH())->toBe(['region' => ['us']])
        ->and(ChargeFilterValue::query()->where('charge_filter_id', $filters[0]->id)->count())->toBe(1);
})->group('ledger:svc:ChargeFilters.CreateOrUpdateBatchService');

it('suffices duplicated codes derived from the same values hash', function (): void {
    $charge = filterCharge();

    $result = CreateOrUpdateBatchService::call(charge: $charge, filtersParams: [
        ['values' => ['region' => ['us']]],
    ]);

    $baseCode = $result->filters[0]->code;

    // A second batch with the same values but an existing filter is matched
    // by values — codes stay unique; exercise next_free_code directly.
    expect(ChargeFilter::nextFreeCode($baseCode, [$baseCode]))->toBe($baseCode.'_2')
        ->and(ChargeFilter::nextFreeCode('fresh', [$baseCode]))->toBe('fresh');
});

it('updates an existing filter matched by values', function (): void {
    $charge = filterCharge();

    CreateOrUpdateBatchService::call(charge: $charge, filtersParams: [
        ['values' => ['region' => ['us']], 'properties' => ['amount' => '10']],
    ]);

    $result = CreateOrUpdateBatchService::call(charge: $charge->refresh(), filtersParams: [
        ['values' => ['region' => ['us']], 'properties' => ['amount' => '99'], 'invoice_display_name' => 'US only'],
    ]);

    expect($result->success())->toBeTrue();

    $filters = $charge->filters()->get();

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->properties)->toBe(['amount' => '99'])
        ->and($filters[0]->invoice_display_name)->toBe('US only');
});

it('removes filters that are no longer in the payload', function (): void {
    $charge = filterCharge();

    CreateOrUpdateBatchService::call(charge: $charge, filtersParams: [
        ['values' => ['region' => ['us']]],
        ['values' => ['region' => ['eu']]],
    ]);

    $result = CreateOrUpdateBatchService::call(charge: $charge->refresh(), filtersParams: [
        ['values' => ['region' => ['us']]],
    ]);

    expect($result->success())->toBeTrue()
        ->and($charge->filters()->count())->toBe(1)
        ->and($charge->filters()->withTrashed()->count())->toBe(2);
});

it('removes all filters when the payload is empty', function (): void {
    $charge = filterCharge();

    CreateOrUpdateBatchService::call(charge: $charge, filtersParams: [
        ['values' => ['region' => ['us']]],
    ]);

    $result = CreateOrUpdateBatchService::call(charge: $charge->refresh(), filtersParams: []);

    expect($result->success())->toBeTrue()
        ->and($charge->filters()->count())->toBe(0)
        ->and(ChargeFilterValue::query()->withTrashed()->where('charge_filter_id', $charge->filters()->withTrashed()->first()->id)->whereNotNull('deleted_at')->count())
        ->toBe(1);
});

it('fails when any filter has empty values', function (): void {
    $charge = filterCharge();

    $result = CreateOrUpdateBatchService::call(charge: $charge, filtersParams: [
        ['values' => ['region' => ['us']]],
        ['values' => []],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['values'])->toBe(['value_is_mandatory']);
});

it('keeps input order via monotonically increasing timestamps', function (): void {
    $charge = filterCharge();

    $result = CreateOrUpdateBatchService::call(charge: $charge, filtersParams: [
        ['values' => ['region' => ['eu']]],
        ['values' => ['region' => ['us']]],
    ]);

    $filters = $result->filters;

    expect($filters[0]->toH())->toBe(['region' => ['eu']])
        ->and($filters[1]->toH())->toBe(['region' => ['us']])
        ->and($filters[0]->updated_at->lessThanOrEqualTo($filters[1]->updated_at))->toBeTrue();
});
