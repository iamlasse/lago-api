<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Customer;
use App\Models\DailyUsage;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Models\BillableMetric;
use App\Services\DailyUsages\ComputeService;

uses()->group('ledger:svc:DailyUsages.ComputeService');

/**
 * Port of Rails' spec/services/daily_usages/compute_service_spec.rb (the
 * scenarios the port supports).
 */
function csTimestamp(): CarbonImmutable
{
    return CarbonImmutable::parse('2024-10-22 00:05:00', 'UTC');
}

function csSetup(array $subscriptionAttributes = [], array $metricAttributes = []): array
{
    $metric = BillableMetric::factory()->create($metricAttributes + [
        'code' => 'api_calls',
        'field_name' => 'calls',
    ]);
    $organization = $metric->organization;
    $customer = Customer::factory()->for($organization)->create();
    $subscription = Subscription::factory()->for($customer)->calendar()->create($subscriptionAttributes + [
        'organization_id' => $organization->id,
        'plan_id' => App\Models\Plan::factory()->for($organization),
        'started_at' => csTimestamp()->subYear(),
        'subscription_at' => csTimestamp()->subYear(),
        'activated_at' => csTimestamp()->subYear(),
    ]);
    $charge = App\Models\Charge::factory()->standard()->create([
        'properties' => ['amount' => '100'],
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $subscription->plan_id,
    ]);

    return [$organization, $customer, $subscription, $metric, $charge];
}

function csEvent(Subscription $subscription, BillableMetric $metric, CarbonImmutable $timestamp): Event
{
    return Event::factory()->create([
        'organization_id' => $subscription->organization_id,
        'external_subscription_id' => $subscription->external_id,
        'code' => $metric->code,
        'timestamp' => $timestamp,
        'created_at' => $timestamp,
        'properties' => ['calls' => 3],
    ]);
}

it('does not create a daily usage when there is no usage', function (): void {
    [, , $subscription] = csSetup();

    expect(DailyUsage::query()->count())->toBe(0);

    ComputeService::call(subscription: $subscription, timestamp: csTimestamp());

    expect(DailyUsage::query()->count())->toBe(0);
});

it('creates a daily usage from the events', function (): void {
    [$organization, $customer, $subscription, $metric] = csSetup();

    csEvent($subscription, $metric, csTimestamp()->subHours(2));

    $this->travelTo(csTimestamp());

    $result = ComputeService::call(subscription: $subscription, timestamp: csTimestamp());

    expect($result->success())->toBeTrue()
        ->and($result->daily_usage)->not->toBeNull();

    $dailyUsage = $result->daily_usage;

    expect($dailyUsage->organization_id)->toBe($organization->id)
        ->and($dailyUsage->customer_id)->toBe($customer->id)
        ->and($dailyUsage->subscription_id)->toBe($subscription->id)
        ->and($dailyUsage->external_subscription_id)->toBe($subscription->external_id)
        ->and($dailyUsage->usage_date->toDateString())->toBe('2024-10-21')
        ->and($dailyUsage->usage)->toBeArray()
        ->and($dailyUsage->usage_diff)->toBeArray()
        ->and($dailyUsage->usage['charges_usage']['charges_usage'])->toHaveCount(1)
        // 3 units x 100.00 EUR (properties.amount is in currency units).
        ->and($dailyUsage->usage['amount_cents'])->toBe(30000);
});

it('returns the existing daily usage for the computed day', function (): void {
    [, , $subscription] = csSetup();

    $existing = DailyUsage::factory()->forSubscription($subscription)->create([
        'usage_date' => '2024-10-21',
    ]);

    $result = ComputeService::call(subscription: $subscription, timestamp: csTimestamp());

    expect($result->success())->toBeTrue()
        ->and($result->daily_usage->is($existing))->toBeTrue();
});

it('takes the billing entity timezone into account', function (): void {
    $metric = BillableMetric::factory()->create(['code' => 'api_calls', 'field_name' => 'calls']);
    $organization = $metric->organization;
    $customer = Customer::factory()->for($organization)->create();
    $customer->billingEntity->update(['timezone' => 'America/Sao_Paulo']);
    $subscription = Subscription::factory()->for($customer)->create([
        'organization_id' => $organization->id,
        'plan_id' => App\Models\Plan::factory()->for($organization),
    ]);

    // The Sao Paulo day of 2024-10-21 starts at 2024-10-21 03:00 UTC, so the
    // row stamped 2024-10-21 02:00 UTC is still "2024-10-20" there.
    $existing = DailyUsage::factory()->forSubscription($subscription)->create([
        'usage_date' => '2024-10-20',
    ]);

    $result = ComputeService::call(subscription: $subscription, timestamp: csTimestamp());

    expect($result->success())->toBeTrue()
        ->and($result->daily_usage->is($existing))->toBeTrue();
});

it('does not create a daily usage on the subscription billing day', function (): void {
    // Anniversary subscription billed on the 22nd of each month; the billing
    // day's usage comes from the periodic invoice, not the cache.
    [, , $subscription, $metric] = csSetup();

    $subscription->update([
        'billing_time' => 'anniversary',
        'subscription_at' => CarbonImmutable::parse('2023-10-22 00:00:00', 'UTC'),
    ]);

    csEvent($subscription, $metric, csTimestamp());

    expect(DailyUsage::query()->count())->toBe(0);

    $this->travelTo(csTimestamp());

    ComputeService::call(subscription: $subscription, timestamp: csTimestamp());

    expect(DailyUsage::query()->count())->toBe(0);
});

it('computes the usage date in the customer timezone', function (): void {
    $metric = BillableMetric::factory()->create(['code' => 'api_calls', 'field_name' => 'calls']);
    $organization = $metric->organization;
    $customer = Customer::factory()->for($organization)->create(['timezone' => 'Australia/Sydney']);
    $subscription = Subscription::factory()->for($customer)->create([
        'organization_id' => $organization->id,
        'plan_id' => App\Models\Plan::factory()->for($organization),
        'started_at' => csTimestamp()->subYear(),
        'subscription_at' => csTimestamp()->subYear(),
        'activated_at' => csTimestamp()->subYear(),
    ]);
    $charge = App\Models\Charge::factory()->standard()->create([
        'properties' => ['amount' => '100'],
        'organization_id' => $organization->id,
        'billable_metric_id' => $metric->id,
        'plan_id' => $subscription->plan_id,
    ]);

    // 2024-10-21 15:05 UTC is already 2024-10-22 02:05 in Sydney — the
    // computed day is still 2024-10-21.
    csEvent($subscription, $metric, CarbonImmutable::parse('2024-10-21 13:00:00', 'UTC'));

    $this->travelTo(CarbonImmutable::parse('2024-10-21 15:05:00', 'UTC'));

    $result = ComputeService::call(
        subscription: $subscription,
        timestamp: CarbonImmutable::parse('2024-10-21 15:05:00', 'UTC'),
    );

    expect($result->success())->toBeTrue()
        ->and($result->daily_usage?->usage_date->toDateString())->toBe('2024-10-21');
});
