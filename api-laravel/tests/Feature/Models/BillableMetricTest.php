<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use App\Models\Organization;
use App\Enums\AggregationType;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CurrentContext::reset();
});

function billableMetricOrganization(): Organization
{
    return CurrentContext::$organization = Organization::factory()->create();
}

it('is valid with the factory defaults', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->make();

    expect($metric->validateAttributes())->toBe([]);
});

it('requires a name and a code', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->make([
        'name' => null,
        'code' => '',
    ]);

    expect($metric->validateAttributes())->toBe([
        'name' => ['value_is_mandatory'],
        'code' => ['value_is_mandatory'],
    ]);
});

it('requires a field_name for aggregations that aggregate on a property', function () {
    $organization = billableMetricOrganization();

    $sum = BillableMetric::factory()->for($organization)->sum()->make(['field_name' => null]);
    $count = BillableMetric::factory()->for($organization)->make(['field_name' => null]);
    $custom = BillableMetric::factory()->for($organization)->custom()->make(['field_name' => null]);

    expect($sum->validateAttributes())->toHaveKey('field_name', ['value_is_mandatory'])
        ->and($count->validateAttributes())->not->toHaveKey('field_name')
        ->and($custom->validateAttributes())->not->toHaveKey('field_name');
});

it('requires a custom_aggregator for custom_agg', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->custom()->make([
        'custom_aggregator' => null,
    ]);

    expect($metric->validateAttributes())->toHaveKey('custom_aggregator', ['value_is_mandatory']);
});

it('rejects an invalid aggregation type and leaves it unassigned', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->make([
        'aggregation_type' => 'invalid_agg',
    ]);

    expect($metric->aggregation_type)->toBeNull()
        ->and($metric->validateAttributes())->toHaveKey('aggregation_type', ['value_is_invalid']);
});

it('keeps the previous aggregation type when a stored metric is assigned an invalid one', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->sum()->create();
    $metric->aggregation_type = 'invalid_agg';

    expect($metric->validateAttributes())->not->toHaveKey('aggregation_type')
        ->and($metric->getAttributes()['aggregation_type'])->toBe(AggregationType::SumAgg->value);
});

it('validates the uniqueness of the code per organization, ignoring discarded metrics', function () {
    $organization = billableMetricOrganization();
    $otherOrganization = Organization::factory()->create();

    BillableMetric::factory()->for($organization)->create(['code' => 'api_calls']);
    BillableMetric::factory()->for($organization)->discarded()->create(['code' => 'old_calls']);

    expect(BillableMetric::factory()->for($organization)->make(['code' => 'api_calls'])->validateAttributes())
        ->toBe(['code' => ['value_already_exist']])
        ->and(BillableMetric::factory()->for($otherOrganization)->make(['code' => 'api_calls'])->validateAttributes())
        ->toBe([])
        ->and(BillableMetric::factory()->for($organization)->make(['code' => 'old_calls'])->validateAttributes())
        ->toBe([]);
});

it('ignores the stored record itself when validating the code uniqueness', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->create(['code' => 'api_calls']);

    $metric->name = 'Renamed';

    expect($metric->validateAttributes())->toBe([]);
});

it('resets the field_name when the aggregation type is count_agg', function () {
    $organization = billableMetricOrganization();

    $built = BillableMetric::factory()->for($organization)->make([
        'aggregation_type' => 'count_agg',
        'field_name' => 'custom_field',
    ]);

    expect($built->validateAttributes())->toBe([])
        ->and($built->field_name)->toBeNull();

    $stored = BillableMetric::factory()->for($organization)->sum()->create(['field_name' => 'custom_field']);
    $stored->aggregation_type = 'count_agg';

    expect($stored->validateAttributes())->toBe([]);

    $stored->save();

    expect($stored->fresh()->field_name)->toBeNull();
});

it('rejects recurring with aggregation types that do not support it', function () {
    $organization = billableMetricOrganization();

    $max = BillableMetric::factory()->for($organization)->max()->make(['recurring' => true]);
    $latest = BillableMetric::factory()->for($organization)->latest()->make(['recurring' => true]);
    $count = BillableMetric::factory()->for($organization)->make(['recurring' => true]);
    $sum = BillableMetric::factory()->for($organization)->sum()->make(['recurring' => true]);

    $incompatible = ['recurring' => ['not_compatible_with_aggregation_type']];

    expect($max->validateAttributes())->toBe($incompatible)
        ->and($latest->validateAttributes())->toBe($incompatible)
        ->and($count->validateAttributes())->toBe($incompatible)
        ->and($sum->validateAttributes())->toBe([]);
});

it('rejects a weighted_sum metric without a weighted_interval', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->weightedSum()->make([
        'weighted_interval' => null,
    ]);

    expect($metric->validateAttributes())->toHaveKey('weighted_interval', ['value_is_invalid']);
});

it('rejects an unknown weighted_interval value', function () {
    $metric = BillableMetric::factory()->for(billableMetricOrganization())->weightedSum()->make([
        'weighted_interval' => 'hours',
    ]);

    expect($metric->validateAttributes())->toHaveKey('weighted_interval', ['value_is_invalid']);
});

it('rejects an invalid rounding_function and allows a null one', function () {
    $organization = billableMetricOrganization();

    $invalid = BillableMetric::factory()->for($organization)->sum()->make(['rounding_function' => 'truncate']);
    $nil = BillableMetric::factory()->for($organization)->sum()->make(['rounding_function' => null]);

    expect($invalid->rounding_function)->toBeNull()
        ->and($invalid->validateAttributes())->toBe(['rounding_function' => ['value_is_invalid']])
        ->and($nil->validateAttributes())->toBe([]);
});

it('reports the aggregation types payable in advance', function () {
    $payable = ['count_agg', 'sum_agg', 'unique_count_agg', 'custom_agg'];

    foreach (AggregationType::cases() as $type) {
        $metric = BillableMetric::factory()->for(billableMetricOrganization())->make([
            'aggregation_type' => $type,
            'field_name' => 'value',
            'custom_aggregator' => 'def aggregate(event, agg, aggregation_properties); agg; end',
        ]);

        if ($type === AggregationType::WeightedSumAgg) {
            $metric->weighted_interval = 'seconds';
        }

        expect($metric->payableInAdvance())->toBe(in_array($type->label(), $payable, true));
    }
});

it('detects whether the metric is attached to a plan', function () {
    $organization = billableMetricOrganization();
    $metric = BillableMetric::factory()->for($organization)->sum()->create();

    $planId = (string) Str::uuid();
    DB::table('plans')->insert([
        'id' => $planId,
        'organization_id' => $organization->id,
        'name' => 'Standard',
        'code' => 'standard',
        'amount_currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($metric->attachedToPlan())->toBeFalse();

    DB::table('charges')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'billable_metric_id' => $metric->id,
        'code' => 'standard',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($metric->attachedToPlan())->toBeTrue();
});
