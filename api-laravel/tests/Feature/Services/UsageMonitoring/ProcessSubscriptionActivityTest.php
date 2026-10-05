<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Support\Facades\Queue;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Jobs\UsageMonitoring\ProcessLifetimeUsageAlertJob;
use App\Services\UsageMonitoring\ProcessSubscriptionActivityService;

uses()->group('ledger:svc:UsageMonitoring.ProcessSubscriptionActivityService');

/**
 * Ports of Rails' spec/services/usage_monitoring/process_subscription_activity_service_spec.rb
 * (the core scenarios) — the fan-out step of the subscription-activity
 * pipeline: progressive billing recalculation, alert processing and the
 * activity drop, with the errors of the branches collected and raised after
 * the activity is deleted.
 */
function activityScenario(array $organizationAttributes = [], array $subscriptionAttributes = []): array
{
    $organization = Organization::factory()->create($organizationAttributes + ['premium_integrations' => []]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create($subscriptionAttributes + [
        'organization_id' => $organization->id,
        'external_id' => 'activity-sub-1',
        'status' => 1,
    ]);
    SubscriptionActivity::insertFor($subscription, (string) $organization->id);
    $activity = SubscriptionActivity::query()
        ->where('organization_id', $organization->id)
        ->where('subscription_id', $subscription->id)
        ->firstOrFail();

    return [$organization, $plan, $customer, $subscription, $activity];
}

it('drops the stale activity of an inactive subscription', function (): void {
    Queue::fake();

    [, , , , $activity] = activityScenario(subscriptionAttributes: [
        'status' => App\Enums\SubscriptionStatus::Terminated->value,
    ]);

    $result = ProcessSubscriptionActivityService::call(subscriptionActivity: $activity);

    expect($result->success())->toBeTrue()
        ->and(SubscriptionActivity::query()->count())->toBe(0);
});

it('deletes the activity and succeeds without premium flags or alerts', function (): void {
    Queue::fake();

    [, , , , $activity] = activityScenario();

    $result = ProcessSubscriptionActivityService::call(subscriptionActivity: $activity);

    expect($result->success())->toBeTrue()
        ->and(SubscriptionActivity::query()->count())->toBe(0);
});

it('creates the missing lifetime usage when processing the activity', function (): void {
    Queue::fake();

    [, , , $subscription, $activity] = activityScenario();

    expect($subscription->lifetimeUsage)->toBeNull();

    ProcessSubscriptionActivityService::call(subscriptionActivity: $activity);

    expect($subscription->refresh()->lifetimeUsage)->not->toBeNull()
        ->and(SubscriptionActivity::query()->count())->toBe(0);
});

it('processes the subscription alerts and drops the activity', function (): void {
    Queue::fake();

    [, , , $subscription, $activity] = activityScenario();

    $currentUsageAlert = Alert::factory()->forSubscription('activity-sub-1')->create([
        'organization_id' => $subscription->organization_id,
    ]);
    $lifetimeUsageAlert = Alert::factory()->forSubscription('activity-sub-1')->create([
        'organization_id' => $subscription->organization_id,
        'alert_type' => 'lifetime_usage_amount',
    ]);
    $metricUnitsAlert = Alert::factory()->forSubscription('activity-sub-1')->create([
        'organization_id' => $subscription->organization_id,
        'alert_type' => 'billable_metric_lifetime_usage_units',
        'billable_metric_id' => App\Models\BillableMetric::factory()->create([
            'organization_id' => $subscription->organization_id,
        ])->id,
    ]);

    $result = ProcessSubscriptionActivityService::call(subscriptionActivity: $activity);

    expect($result->success())->toBeTrue()
        ->and(SubscriptionActivity::query()->count())->toBe(0)
        // The billable-metric lifetime-usage alert is deferred to its job.
        ->and(Queue::pushedJobs()[ProcessLifetimeUsageAlertJob::class] ?? [])->toHaveCount(1);

    expect($currentUsageAlert->refresh()->last_processed_at)->not->toBeNull()
        ->and($lifetimeUsageAlert->refresh()->last_processed_at)->not->toBeNull()
        ->and($metricUnitsAlert->refresh()->last_processed_at)->toBeNull();
});

it('does not enqueue the lifetime-usage job when the alert is another type', function (): void {
    Queue::fake();

    [, , , , $activity] = activityScenario();

    Alert::factory()->forSubscription('activity-sub-1')->create([
        'organization_id' => Subscription::find($activity->subscription_id)->organization_id,
    ]);

    ProcessSubscriptionActivityService::call(subscriptionActivity: $activity);

    expect(Queue::pushedJobs()[ProcessLifetimeUsageAlertJob::class] ?? [])->toHaveCount(0);
});
