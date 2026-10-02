<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use App\Models\Subscription;

/**
 * Port of the Rails Subscription model helpers with date semantics
 * (downgrade_plan_date in particular).
 */
it('returns the day after the current period end when the next subscription is a pending downgrade', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-25 12:00:00', 'UTC'));

    $customer = Customer::factory()->create();
    $plan = Plan::factory()->create(['amount_cents' => 500_00]);
    $lowerPlan = Plan::factory()->create(['amount_cents' => 100_00]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'billing_time' => 'anniversary',
        'subscription_at' => '2026-04-22 00:00:00',
        'started_at' => '2026-04-22 00:00:00',
    ]);

    Subscription::factory()->pending()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'plan_id' => $lowerPlan->id,
        'previous_subscription_id' => $subscription->id,
        'subscription_at' => now(),
    ]);

    // Rails: DatesService.new_instance(self, Time.current).next_end_of_period + 1.day.
    // The current period ends May 21 (anniversary day 22), so the downgrade
    // day is May 22 at 00:00.
    expect($subscription->downgradePlanDate()?->toDateString())->toBe('2026-05-22');
});

it('returns null when there is no next subscription', function (): void {
    $customer = Customer::factory()->create();
    $plan = Plan::factory()->create();

    $subscription = Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
    ]);

    expect($subscription->downgradePlanDate())->toBeNull();
});

it('returns the started day when the next subscription is an active downgrade', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-25 12:00:00', 'UTC'));

    $customer = Customer::factory()->create();
    $plan = Plan::factory()->create(['amount_cents' => 500_00]);
    $lowerPlan = Plan::factory()->create(['amount_cents' => 100_00]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'billing_time' => 'anniversary',
        'subscription_at' => '2026-04-22 00:00:00',
        'started_at' => '2026-04-22 00:00:00',
    ]);

    Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'plan_id' => $lowerPlan->id,
        'status' => 'active',
        'previous_subscription_id' => $subscription->id,
        'subscription_at' => '2026-05-22 00:00:00',
        'started_at' => '2026-05-22 00:00:00',
    ]);

    // Rails: next_subscription.started_at&.to_date when
    // next_subscription.active? && downgraded?.
    expect($subscription->downgradePlanDate()?->toDateString())->toBe('2026-05-22');
});

it('returns null when the next subscription is neither pending nor an active downgrade', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-25 12:00:00', 'UTC'));

    $customer = Customer::factory()->create();
    $plan = Plan::factory()->create(['amount_cents' => 100_00]);
    $higherPlan = Plan::factory()->create(['amount_cents' => 500_00]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'billing_time' => 'anniversary',
        'subscription_at' => '2026-04-22 00:00:00',
        'started_at' => '2026-04-22 00:00:00',
    ]);

    // Rails: `return unless next_subscription.pending?` — an active next
    // subscription that is not a downgrade yields null (the gate is on the
    // NEXT subscription, not on the subscription itself).
    Subscription::factory()->create([
        'organization_id' => $customer->organization_id,
        'customer_id' => $customer->id,
        'plan_id' => $higherPlan->id,
        'status' => 'active',
        'previous_subscription_id' => $subscription->id,
        'subscription_at' => '2026-05-22 00:00:00',
        'started_at' => '2026-05-22 00:00:00',
    ]);

    expect($subscription->downgradePlanDate())->toBeNull();
});
