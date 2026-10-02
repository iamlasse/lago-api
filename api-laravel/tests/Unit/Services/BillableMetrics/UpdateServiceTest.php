<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ForbiddenFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\BillableMetrics\UpdateService;

beforeEach(function () {
    CurrentContext::reset();
});

function updateMetricParams(array $overrides = []): array
{
    return [
        'name' => 'New Metric',
        'code' => 'new_metric',
        'description' => 'New metric description',
        'aggregation_type' => 'sum_agg',
        'field_name' => 'field_value',
        'expression' => '1 + 3',
        'rounding_function' => 'ceil',
        'rounding_precision' => 2,
        ...$overrides,
    ];
}

it('updates the billable metric', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $metric = BillableMetric::factory()->for($organization)->create();

    $result = UpdateService::call(billableMetric: $metric, params: updateMetricParams());

    expect($result->success())->toBeTrue()
        ->and($result->billable_metric->id)->toBe($metric->id)
        ->and($result->billable_metric->name)->toBe('New Metric')
        ->and($result->billable_metric->code)->toBe('new_metric')
        ->and($result->billable_metric->aggregation_type->label())->toBe('sum_agg')
        ->and($result->billable_metric->field_name)->toBe('field_value')
        ->and($result->billable_metric->rounding_function->value)->toBe('ceil')
        ->and($result->billable_metric->rounding_precision)->toBe(2)
        ->and($result->billable_metric->expression)->toBe('1 + 3');
})->group('ledger:svc:BillableMetrics.UpdateService');

it('fails when the billable metric is not found', function () {
    $result = UpdateService::call(billableMetric: null, params: updateMetricParams());

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('billable_metric_not_found');
});

it('returns a validation error when the name is blank', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $metric = BillableMetric::factory()->for($organization)->create();

    $result = UpdateService::call(billableMetric: $metric, params: [
        'name' => null,
        'code' => 'new_metric',
        'aggregation_type' => 'count_agg',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});

it('returns a forbidden failure when switching to the custom aggregation without the feature', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $metric = BillableMetric::factory()->for($organization)->create();

    $result = UpdateService::call(billableMetric: $metric, params: ['aggregation_type' => 'custom_agg']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ForbiddenFailure::class)
        ->and($metric->fresh()->aggregation_type->label())->toBe('count_agg');
});

it('updates only the name and the description when attached to a plan', function () {
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $metric = BillableMetric::factory()->for($organization)->create();

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
    DB::table('charges')->insert([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'plan_id' => $planId,
        'billable_metric_id' => $metric->id,
        'code' => 'standard',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = UpdateService::call(billableMetric: $metric, params: updateMetricParams());

    expect($result->success())->toBeTrue()
        ->and($result->billable_metric->name)->toBe('New Metric')
        ->and($result->billable_metric->description)->toBe('New metric description')
        ->and($result->billable_metric->fresh()->code)->not->toBe('new_metric')
        ->and($result->billable_metric->fresh()->aggregation_type->label())->toBe('count_agg')
        ->and($result->billable_metric->fresh()->field_name)->toBeNull()
        ->and($result->billable_metric->fresh()->rounding_precision)->toBeNull()
        ->and($result->billable_metric->fresh()->expression)->toBe('');
});

// TODO(port): the webhook (SendWebhookJob "billable_metric.updated"), activity
// log, with_lock racing scenario, expression cache expiry and filters args
// scenarios from the Rails spec are covered by TODO(port) hook points in the
// service.
