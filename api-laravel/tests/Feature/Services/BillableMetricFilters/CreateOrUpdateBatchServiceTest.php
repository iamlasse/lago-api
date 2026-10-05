<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Charge;
use App\Models\ChargeFilter;
use App\Models\ProductFilter;
use App\Models\BillableMetric;
use App\Models\ChargeFilterValue;
use App\Models\ProductFilterValue;
use App\Models\BillableMetricFilter;
use Illuminate\Support\Facades\Queue;
use App\Services\BillableMetricFilters\CreateOrUpdateBatchService;

/**
 * Port of spec/services/billable_metric_filters/create_or_update_batch_service_spec.rb
 * (core scenarios) — the metrics-slice TODO(port) that this slice delivers.
 */
function bmfMetric(): BillableMetric
{
    $plan = Plan::factory()->create();
    $metric = BillableMetric::factory()->create(['organization_id' => $plan->organization_id]);
    // Touch the plan so the charge below has somewhere to live.
    Charge::factory()->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'billable_metric_id' => $metric->id,
    ]);

    return $metric;
}

it('creates filters and discards ones absent from the batch', function (): void {
    Queue::fake();
    $metric = bmfMetric();

    $first = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['us', 'eu']],
        ['key' => 'tier', 'values' => ['gold']],
    ]);

    expect($first->success())->toBeTrue()
        ->and($first->filters)->toHaveCount(2)
        ->and($metric->filters()->pluck('key')->all())->toBe(['region', 'tier']);

    $second = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['us', 'apac']],
    ]);

    expect($second->success())->toBeTrue()
        ->and($metric->filters()->pluck('key')->all())->toBe(['region'])
        ->and($metric->filters()->first()->values)->toBe(['us', 'apac'])
        // discarded, not destroyed
        ->and(BillableMetricFilter::withTrashed()->where('key', 'tier')->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('trims removed values and discards emptied charge filter values', function (): void {
    Queue::fake();
    $metric = bmfMetric();

    CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['us', 'eu', 'apac']],
    ]);

    // Simulate a charge filter value consuming us+eu.
    $filter = $metric->filters()->first();
    $chargeFilter = ChargeFilter::query()->create([
        'organization_id' => $metric->organization_id,
        'charge_id' => $metric->charges->first()->id,
        'code' => 'cf1',
        'values' => ['region' => ['us', 'eu']],
    ]);
    ChargeFilterValue::query()->create([
        'organization_id' => $metric->organization_id,
        'charge_filter_id' => $chargeFilter->id,
        'billable_metric_filter_id' => $filter->id,
        'values' => ['us', 'eu'],
    ]);

    $result = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['us']],
    ]);

    expect($result->success())->toBeTrue();

    $filterValue = ChargeFilterValue::withTrashed()->where('billable_metric_filter_id', $filter->id)->first();

    // Trimmed to the surviving intersection, not discarded; the charge filter
    // stays alive (its values row still carries a surviving value).
    expect($filterValue->deleted_at)->toBeNull()
        ->and($filterValue->values)->toBe(['us'])
        ->and(ChargeFilter::withTrashed()->find($chargeFilter->id)->deleted_at)->toBeNull();

    // Removing ALL of the value row's values discards the row AND the emptied
    // charge filter.
    $second = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['apac']],
    ]);

    expect($second->success())->toBeTrue()
        ->and(ChargeFilterValue::withTrashed()->find($filterValue->id)->deleted_at)->not->toBeNull()
        ->and(ChargeFilter::withTrashed()->find($chargeFilter->id)->deleted_at)->not->toBeNull();
});

it('rejects duplicate keys and blank values', function (): void {
    $metric = bmfMetric();

    $dup = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['us']],
        ['key' => 'region', 'values' => ['eu']],
    ]);

    expect($dup->success())->toBeFalse()
        ->and($dup->getError()->getMessage())->toContain('value_already_exist');

    $blank = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => []],
    ]);

    expect($blank->success())->toBeFalse()
        ->and($blank->getError()->getMessage())->toContain('value_is_mandatory');
});

it('blocks edits that would orphan referenced product filter values', function (): void {
    Queue::fake();
    $metric = bmfMetric();

    CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['us', 'eu']],
    ]);

    $productFilter = ProductFilter::factory()->create([
        'organization_id' => $metric->organization_id,
        'product_id' => App\Models\Product::factory()->create(['organization_id' => $metric->organization_id])->id,
    ]);
    $filter = $metric->filters()->first();
    ProductFilterValue::factory()->create([
        'organization_id' => $metric->organization_id,
        'product_filter_id' => $productFilter->id,
        'billable_metric_filter_id' => $filter->id,
        'value' => 'eu',
    ]);

    // Trimming the referenced value is blocked.
    $trim = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: [
        ['key' => 'region', 'values' => ['us']],
    ]);

    dump('TRIM result success='.var_export($trim->success(), true).' err='.$trim->getError()?->getMessage());
    expect($trim->success())->toBeFalse()
        ->and($trim->getError()->getMessage())->toContain('referenced_by_product_filter');

    // Full removal is blocked too.
    $remove = CreateOrUpdateBatchService::call(billableMetric: $metric, filtersParams: []);

    expect($remove->success())->toBeFalse()
        ->and($remove->getError()->getMessage())->toContain('referenced_by_product_filter');
});
