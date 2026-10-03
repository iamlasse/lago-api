<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use App\Serializers\V1\BillableMetricSerializer;

beforeEach(function (): void {
    CurrentContext::reset();
});

it('serializes the billable metric', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $metric = BillableMetric::factory()->for($organization)->weightedSum()->create([
        'name' => 'Weighted Metric',
        'code' => 'weighted_metric',
        'description' => 'Weighted metric description',
        'rounding_function' => 'ceil',
        'rounding_precision' => 2,
    ]);

    $result = json_decode(
        (new BillableMetricSerializer($metric, ['root_name' => 'billable_metric']))->toJson(),
        true,
    );

    expect($result['billable_metric']['lago_id'])->toBe($metric->id)
        ->and($result['billable_metric']['name'])->toBe('Weighted Metric')
        ->and($result['billable_metric']['code'])->toBe('weighted_metric')
        ->and($result['billable_metric']['description'])->toBe('Weighted metric description')
        ->and($result['billable_metric']['aggregation_type'])->toBe('weighted_sum_agg')
        ->and($result['billable_metric']['field_name'])->toBe('value')
        ->and($result['billable_metric']['created_at'])->toBe($metric->created_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($result['billable_metric']['rounding_function'])->toBe('ceil')
        ->and($result['billable_metric']['rounding_precision'])->toBe(2)
        ->and($result['billable_metric']['weighted_interval'])->toBe('seconds')
        ->and($result['billable_metric']['recurring'])->toBeFalse()
        ->and($result['billable_metric']['expression'])->toBeNull()
        ->and($result['billable_metric']['filters'])->toBe([]);
});

it('serializes the metric filters with sorted values', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $metric = BillableMetric::factory()->for($organization)->create();
    $metric->filters()->createMany([
        ['organization_id' => $organization->id, 'key' => 'region', 'values' => ['us', 'eu']],
    ]);

    $result = (new BillableMetricSerializer($metric, ['root_name' => 'billable_metric']))->serialize();

    expect($result['filters'])->toBe([
        ['key' => 'region', 'values' => ['eu', 'us']],
    ]);
});

it('omits the counters unless included and returns zero counts when included', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $metric = BillableMetric::factory()->for($organization)->create();

    $plain = (new BillableMetricSerializer($metric, ['root_name' => 'billable_metric']))->serialize();
    $withCounters = (new BillableMetricSerializer($metric, [
        'root_name' => 'billable_metric',
        'includes' => ['counters'],
    ]))->serialize();

    expect($plain)->not->toHaveKey('active_subscriptions_count')
        ->and($withCounters['active_subscriptions_count'])->toBe(0)
        ->and($withCounters['draft_invoices_count'])->toBe(0)
        ->and($withCounters['plans_count'])->toBe(0);
});
