<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use App\Services\Failures\ForbiddenFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\BillableMetrics\CreateService;

beforeEach(function (): void {
    CurrentContext::reset();
});

function createMetricArgs(Organization $organization, array $overrides = []): array
{
    return [
        'name' => 'New Metric',
        'code' => 'new_metric',
        'description' => 'New metric description',
        'organization_id' => $organization->id,
        'aggregation_type' => 'count_agg',
        'expression' => '1 + 2',
        'rounding_function' => 'ceil',
        'rounding_precision' => 2,
        'recurring' => false,
        ...$overrides,
    ];
}

it('creates a billable metric', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(createMetricArgs($organization));

    expect($result->success())->toBeTrue()
        ->and(BillableMetric::count())->toBe(1)
        ->and($result->billable_metric->organization_id)->toBe($organization->id)
        ->and($result->billable_metric->name)->toBe('New Metric')
        ->and($result->billable_metric->code)->toBe('new_metric')
        ->and($result->billable_metric->getAttributes()['aggregation_type'])->toBe(0)
        ->and($result->billable_metric->rounding_function->value)->toBe('ceil')
        ->and($result->billable_metric->rounding_precision)->toBe(2)
        ->and($result->billable_metric->expression)->toBe('1 + 2');
})->group('ledger:svc:BillableMetrics.CreateService');

it('creates a billable metric with a code used by a deleted metric', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    BillableMetric::factory()->for($organization)->create([
        'code' => 'new_metric',
        'deleted_at' => now(),
    ]);

    $result = CreateService::call(createMetricArgs($organization));

    expect($result->success())->toBeTrue()
        ->and($organization->billableMetrics()->withTrashed()->count())->toBe(2)
        ->and($organization->billableMetrics()->withTrashed()->pluck('code')->unique()->values()->all())
        ->toBe(['new_metric']);
});

it('returns a validation error when the code already exists', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    BillableMetric::factory()->for($organization)->create(['code' => 'new_metric']);

    $result = CreateService::call(createMetricArgs($organization));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['code'])->toBe(['value_already_exist']);
});

it('returns a validation error for an unknown aggregation type', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(createMetricArgs($organization, [
        'aggregation_type' => 'invalid_agg',
        'expression' => null,
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['aggregation_type'])->toBe(['value_is_invalid']);
});

it('returns a forbidden failure for the custom aggregation without the organization feature', function (): void {
    $organization = CurrentContext::$organization = Organization::factory()->create();

    $result = CreateService::call(createMetricArgs($organization, [
        'aggregation_type' => 'custom_agg',
        'expression' => null,
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ForbiddenFailure::class)
        ->and(BillableMetric::count())->toBe(0);
});

it('rejects a custom aggregation metric without a custom_aggregator', function (): void {
    // NOTE: Rails' CreateService passes neither custom_aggregator nor
    // filters to BillableMetric.create!, so a custom_agg create without a
    // previously assigned custom aggregator fails the model's presence
    // validation — ported as is.
    $organization = CurrentContext::$organization = Organization::factory()->create([
        'custom_aggregation' => true,
    ]);

    $result = CreateService::call(createMetricArgs($organization, [
        'aggregation_type' => 'custom_agg',
        'expression' => null,
        'custom_aggregator' => 'def aggregate(event, agg, aggregation_properties); agg; end',
    ]));

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['custom_aggregator'])->toBe(['value_is_mandatory']);
});

// TODO(port): the webhook (SendWebhookJob "billable_metric.created"), Segment
// tracking, activity log, expression cache expiry and filters args scenarios
// from the Rails spec are covered by TODO(port) hook points in the service.
