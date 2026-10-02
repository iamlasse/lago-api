<?php

declare(strict_types=1);

use App\Models\Plan;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Services\Subscriptions\TerminateService;

/**
 * Port of spec/services/subscriptions/terminate_service_spec.rb (core scenarios).
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function terminableSubscription(array $overrides = []): Subscription
{
    $plan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 4900]);
    $customer = App\Models\Customer::factory()->create();

    return Subscription::factory()->create(array_merge([
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'external_id' => 'sub_term',
        'started_at' => '2024-04-01 00:00:00',
        'subscription_at' => '2024-04-01 00:00:00',
        'activated_at' => '2024-04-01 00:00:00',
    ], $overrides));
}

it('terminates an active subscription', function (): void {
    $subscription = terminableSubscription();

    $result = TerminateService::call(subscription: $subscription);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->terminated())->toBeTrue()
        ->and($result->subscription->terminated_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00');
})->group('ledger:svc:Subscriptions.TerminateService');

it('honors a passed on_termination_invoice of skip for pay-in-advance plans', function (): void {
    $subscription = terminableSubscription([
        'plan_id' => Plan::factory()->create(['interval' => 'monthly', 'pay_in_advance' => true])->id,
    ]);

    $result = TerminateService::call(
        subscription: $subscription,
        onTerminationInvoice: 'skip',
        onTerminationCreditNote: 'skip',
    );

    expect($result->success())->toBeTrue()
        ->and($subscription->fresh()->on_termination_invoice)->toBe('skip')
        ->and($subscription->fresh()->on_termination_credit_note)->toBe('skip');
});

it('cancels a pending subscription on terminate', function (): void {
    $previous = terminableSubscription(['external_id' => 'sub_pending_term']);
    $pending = Subscription::factory()->pending()->create([
        'external_id' => 'sub_pending_term',
        'customer_id' => $previous->customer_id,
        'organization_id' => $previous->organization_id,
        'plan_id' => $previous->plan_id,
        'previous_subscription_id' => $previous->id,
        'subscription_at' => '2024-06-01 00:00:00',
    ]);

    $result = TerminateService::call(subscription: $pending);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->canceled())->toBeTrue()
        ->and($result->subscription->canceled_at)->not->toBeNull();
});

it('fails when the subscription is already canceled', function (): void {
    $subscription = terminableSubscription();
    $subscription->markAsCanceled();
    $subscription->save();

    $result = TerminateService::call(subscription: $subscription);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['subscription_canceled']]);
});

it('fails when the next subscription is incomplete and it is not an upgrade', function (): void {
    $subscription = terminableSubscription();
    Subscription::factory()->incomplete()->create([
        'external_id' => 'sub_term',
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'plan_id' => $subscription->plan_id,
        'previous_subscription_id' => $subscription->id,
    ]);

    $result = TerminateService::call(subscription: $subscription);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['next_subscription_incomplete']]);
});

it('does not fail on an incomplete next subscription during an upgrade', function (): void {
    $subscription = terminableSubscription();
    Subscription::factory()->incomplete()->create([
        'external_id' => 'sub_term',
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'plan_id' => $subscription->plan_id,
        'previous_subscription_id' => $subscription->id,
    ]);

    $result = TerminateService::call(subscription: $subscription, upgrade: true);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->terminated())->toBeTrue();
});

it('cancels a scheduled pending downgrade when the current subscription is terminated', function (): void {
    $subscription = terminableSubscription();
    $pending = Subscription::factory()->pending()->create([
        'external_id' => 'sub_term',
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'plan_id' => $subscription->plan_id,
        'previous_subscription_id' => $subscription->id,
        'subscription_at' => '2024-06-01 00:00:00',
    ]);

    $result = TerminateService::call(subscription: $subscription);

    expect($result->success())->toBeTrue()
        ->and($pending->fresh()->canceled())->toBeTrue();
});

it('keeps the scheduled downgrade when the termination is part of an upgrade', function (): void {
    $subscription = terminableSubscription();
    $pending = Subscription::factory()->pending()->create([
        'external_id' => 'sub_term',
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'plan_id' => $subscription->plan_id,
        'previous_subscription_id' => $subscription->id,
        'subscription_at' => '2024-06-01 00:00:00',
    ]);

    $result = TerminateService::call(subscription: $subscription, upgrade: true);

    expect($result->success())->toBeTrue()
        ->and($pending->fresh()->pending())->toBeTrue();
});

it('does nothing for an already terminated subscription', function (): void {
    $subscription = terminableSubscription();
    $subscription->markAsTerminated('2024-05-01 00:00:00');
    $subscription->save();

    $result = TerminateService::call(subscription: $subscription);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->terminated_at->format('Y-m-d H:i:s'))->toBe('2024-05-01 00:00:00');
});

it('fails when the subscription is missing', function (): void {
    $result = TerminateService::call(subscription: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(App\Services\Failures\NotFoundFailure::class);
});

it('activates the pending next subscription via terminate_and_start_next', function (): void {
    // NOTE: Rails uses this entrypoint from the billing scheduler: the current
    // subscription stays active until its period end, then the pending
    // downgrade activates on the billing day.
    $subscription = terminableSubscription();
    $pending = Subscription::factory()->pending()->create([
        'external_id' => 'sub_term',
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'plan_id' => $subscription->plan_id,
        'previous_subscription_id' => $subscription->id,
        'subscription_at' => '2024-06-01 00:00:00',
    ]);

    $service = new TerminateService(subscription: $subscription);
    $result = $service->terminateAndStartNext(CarbonImmutable::parse('2024-06-01 00:00:00', 'UTC')->getTimestamp());

    expect($result->success())->toBeTrue()
        ->and($result->subscription->id)->toBe($pending->id)
        ->and($pending->fresh()->active())->toBeTrue()
        ->and($pending->fresh()->started_at->format('Y-m-d H:i:s'))->toBe('2024-06-01 00:00:00');
});
