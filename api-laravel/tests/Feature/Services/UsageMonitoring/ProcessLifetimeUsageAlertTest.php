<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Event;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Support\Facades\Queue;
use App\Models\UsageMonitoring\TriggeredAlert;
use App\Services\UsageMonitoring\ProcessLifetimeUsageAlertService;

uses()->group('ledger:svc:UsageMonitoring.ProcessLifetimeUsageAlertService');

/**
 * Ports of Rails' spec/services/usage_monitoring/process_lifetime_usage_alert_service_spec.rb
 * (the core scenarios) — evaluates a billable_metric_lifetime_usage_units
 * alert against the usage of that metric's charges only, guarded against a
 * metric change or deletion while the usage is being built.
 */
function lifetimeAlertScenario(): array
{
    $organization = Organization::factory()->create(['premium_integrations' => ['lifetime_usage']]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->for($plan)->create([
        'organization_id' => $organization->id,
        'external_id' => 'ltu-alert-sub-1',
        'status' => 1,
        'started_at' => now()->utc()->subDays(5),
        'subscription_at' => now()->utc()->subDays(5),
        'activated_at' => now()->utc()->subDays(5),
    ]);
    $metric = BillableMetric::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'api_calls',
        'field_name' => 'calls',
        'aggregation_type' => 1,
    ]);
    $charge = Charge::factory()->standard(['amount' => '2'])->create([
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $plan->id,
    ]);

    return [$organization, $plan, $subscription, $metric, $charge];
}

function lifetimeUsageAlert(Organization $organization, BillableMetric $metric): Alert
{
    return Alert::factory()->forSubscription('ltu-alert-sub-1')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'billable_metric_lifetime_usage_units',
        'billable_metric_id' => $metric->id,
        'code' => 'ltu_units',
        'direction' => 'increasing',
    ]);
}

it('processes the alert against the metric usage and records a trigger', function (): void {
    Queue::fake();

    [$organization, , $subscription, $metric] = lifetimeAlertScenario();
    $alert = lifetimeUsageAlert($organization, $metric);
    App\Models\UsageMonitoring\AlertThreshold::query()->create([
        'organization_id' => $organization->id,
        'usage_monitoring_alert_id' => $alert->id,
        'code' => 'warn',
        'value' => '10',
        'recurring' => false,
    ]);

    Event::factory()->create([
        'organization_id' => $organization->id,
        'external_subscription_id' => $subscription->external_id,
        'code' => 'api_calls',
        'timestamp' => now()->utc()->subDay(),
        'properties' => ['calls' => 25],
    ]);

    $result = ProcessLifetimeUsageAlertService::call(alert: $alert, subscription: $subscription);

    expect($result->success())->toBeTrue()
        ->and($alert->refresh()->last_processed_at)->not->toBeNull()
        ->and(TriggeredAlert::query()->where('usage_monitoring_alert_id', $alert->id)->count())->toBe(1);
});

it('skips the evaluation when the alert has no matching charges', function (): void {
    Queue::fake();

    [$organization, , $subscription, $metric] = lifetimeAlertScenario();
    $alert = lifetimeUsageAlert($organization, $metric);
    Charge::query()->where('billable_metric_id', $metric->id)->delete();

    ProcessLifetimeUsageAlertService::call(alert: $alert, subscription: $subscription);

    expect($alert->refresh()->last_processed_at)->toBeNull()
        ->and(TriggeredAlert::query()->count())->toBe(0);
});

it('skips the evaluation when the subscription is not active', function (): void {
    Queue::fake();

    [$organization, , $subscription, $metric] = lifetimeAlertScenario();
    $alert = lifetimeUsageAlert($organization, $metric);
    $subscription->update(['status' => App\Enums\SubscriptionStatus::Terminated->value]);

    ProcessLifetimeUsageAlertService::call(alert: $alert, subscription: $subscription);

    expect($alert->refresh()->last_processed_at)->toBeNull()
        ->and(TriggeredAlert::query()->count())->toBe(0);
});

it('does nothing for another alert type', function (): void {
    Queue::fake();

    [$organization, , $subscription, $metric] = lifetimeAlertScenario();

    $alert = Alert::factory()->forSubscription('ltu-alert-sub-1')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'current_usage_amount',
    ]);

    ProcessLifetimeUsageAlertService::call(alert: $alert, subscription: $subscription);

    expect($alert->refresh()->last_processed_at)->toBeNull()
        ->and(TriggeredAlert::query()->count())->toBe(0);
});

it('answers not found for an unknown subscription', function (): void {
    Queue::fake();

    [$organization, , , $metric] = lifetimeAlertScenario();
    $alert = Alert::factory()->forSubscription('does-not-exist')->create([
        'organization_id' => $organization->id,
        'alert_type' => 'billable_metric_lifetime_usage_units',
        'billable_metric_id' => $metric->id,
    ]);

    $result = ProcessLifetimeUsageAlertService::call(alert: $alert, subscription: null);

    expect($result->success())->toBeTrue()
        ->and($alert->refresh()->last_processed_at)->toBeNull();
});
