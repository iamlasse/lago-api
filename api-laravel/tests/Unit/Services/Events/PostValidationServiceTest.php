<?php

declare(strict_types=1);

use App\Models\Event;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\Queue;
use Database\Factories\BillableMetricFactory;
use App\Services\Events\PostValidationService;

uses()->group('ledger:svc:Events.PostValidationService');

/**
 * Port of Rails' spec/services/events/post_validation_service_spec.rb —
 * the last-hour MV scan reports invalid codes, missing aggregation
 * properties and invalid filter values, and delivers the events.errors
 * webhook.
 */
function pvEvent(Organization $organization, array $attributes = []): Event
{
    return Event::factory()->create($attributes + [
        'organization_id' => $organization->id,
        // Inside the MV window ([date_trunc('hour', now()) - 1h,
        // date_trunc('hour', now()))).
        'created_at' => now()->startOfHour()->subMinutes(25),
    ]);
}

it('returns the transaction ids of the invalid events', function (): void {
    $organization = Organization::factory()->create();

    $invalidCodeEvent = pvEvent($organization, ['code' => 'unknown_metric_'.fake()->regexify('[a-z]{6}')]);

    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'field_name' => 'amount',
        'aggregation_type' => BillableMetricFactory::SUM_AGG,
    ]);

    $missingAggregationPropertyEvent = pvEvent($organization, [
        'code' => $metric->code,
        'properties' => [],
    ]);

    $negativeAggregationPropertyEvent = pvEvent($organization, [
        'code' => $metric->code,
        'properties' => ['amount' => -12],
    ]);

    // Rails' :billable_metric factory defaults to count_agg (0) — neither
    // field_name nor numeric value mandatory.
    $filteredMetric = BillableMetric::factory()->create(['organization_id' => $organization->id, 'aggregation_type' => 0]);
    $filter = App\Models\BillableMetricFilter::factory()->withValues('region', ['eu-west-1', 'us-east-1'])->create([
        'billable_metric_id' => $filteredMetric->id,
        'organization_id' => $organization->id,
    ]);

    $invalidFilterValuesEvent = pvEvent($organization, [
        'code' => $filteredMetric->code,
        'properties' => [$filter->key => 'us-west-4'],
    ]);

    Illuminate\Support\Facades\DB::statement('REFRESH MATERIALIZED VIEW last_hour_events_mv');

    $result = (new PostValidationService($organization))->callOrFail();

    expect($result->errors['invalid_code'])->toContain($invalidCodeEvent->transaction_id)
        ->and($result->errors['missing_aggregation_property'])->toContain($missingAggregationPropertyEvent->transaction_id)
        ->and($result->errors['missing_aggregation_property'])->not->toContain($negativeAggregationPropertyEvent->transaction_id)
        ->and($result->errors['invalid_filter_values'])->toContain($invalidFilterValuesEvent->transaction_id);

    Queue::assertPushed(SendWebhookJob::class, function (SendWebhookJob $job) use ($organization, $invalidCodeEvent, $missingAggregationPropertyEvent, $invalidFilterValuesEvent): bool {
        return $job->webhookType === 'events.errors'
            && $job->object->is($organization)
            && $job->options === ['errors' => [
                'invalid_code' => [$invalidCodeEvent->transaction_id],
                'missing_aggregation_property' => [$missingAggregationPropertyEvent->transaction_id],
                'invalid_filter_values' => [$invalidFilterValuesEvent->transaction_id],
            ]];
    });
});

it('does not deliver a webhook for another organization', function (): void {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->withoutWebhookEndpoint()->create();

    pvEvent($organization, ['code' => 'unknown_metric_'.fake()->regexify('[a-z]{6}')]);

    Illuminate\Support\Facades\DB::statement('REFRESH MATERIALIZED VIEW last_hour_events_mv');

    (new PostValidationService($otherOrganization))->callOrFail();

    Queue::assertNotPushed(SendWebhookJob::class);
});
