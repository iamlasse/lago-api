<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Support\Facades\Queue;
use App\Jobs\BillSubscriptionJob;
use App\Jobs\SendWebhookJob;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use App\Services\Subscriptions\FreeTrialBillingService;
use App\Jobs\Clock\FreeTrialSubscriptionsBillerJob;

/**
 * Port of spec/services/subscriptions/free_trial_billing_service_spec.rb
 * (core scenario).
 */
beforeEach(function (): void {
    config(['lago.license' => 'premium-license-token']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('closes out an active subscription whose trial just ended', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();

    // The trial is 14 days: started 2024-05-01, trial ends 2024-05-15 (today).
    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
        'pay_in_advance' => true,
        'trial_period' => 14,
    ]);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'external_id' => 'trial_sub',
        'status' => 1, // active
        'started_at' => '2024-05-01 10:00:00',
        'subscription_at' => '2024-05-01 10:00:00',
        'trial_ended_at' => null,
    ]);

    FreeTrialBillingService::call(timestamp: now());

    $subscription->refresh();

    expect($subscription->trial_ended_at)->not->toBeNull()
        ->and($subscription->trial_ended_at->format('Y-m-d H:i'))->toBe('2024-05-15 10:00');

    // The pay-in-advance plan bills at trial end, skipping the charges.
    Queue::assertPushed(BillSubscriptionJob::class, function (BillSubscriptionJob $job) use ($subscription) {
        // The service hydrates its own instance from the SQL pass — compare
        // by id.
        return count($job->subscriptions) === 1
            && $job->subscriptions[0]->id === $subscription->id
            && $job->invoicingReason === 'subscription_starting'
            && $job->skipCharges === true;
    });

    Queue::assertPushed(SendWebhookJob::class, fn (SendWebhookJob $job) => true);
});

it('does not stamp a subscription whose trial has not ended', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
        'pay_in_advance' => true,
        'trial_period' => 30,
    ]);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'external_id' => 'trial_running',
        'status' => 1,
        'started_at' => '2024-05-01 10:00:00',
        'subscription_at' => '2024-05-01 10:00:00',
        'trial_ended_at' => null,
    ]);

    FreeTrialBillingService::call(timestamp: now());

    $subscription->refresh();

    expect($subscription->trial_ended_at)->toBeNull();

    Queue::assertNotPushed(BillSubscriptionJob::class);
});

it('runs from the clock job', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
        'pay_in_advance' => false,
        'trial_period' => 14,
    ]);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'external_id' => 'trial_clock',
        'status' => 1,
        'started_at' => '2024-05-01 10:00:00',
        'subscription_at' => '2024-05-01 10:00:00',
        'trial_ended_at' => null,
    ]);

    (new FreeTrialSubscriptionsBillerJob)->handle();

    $subscription->refresh();

    expect($subscription->trial_ended_at)->not->toBeNull()
        // Arrears plan: no upfront bill at trial end.
        ->and(Queue::pushedJobs()[BillSubscriptionJob::class] ?? [])->toHaveCount(0);
});
