<?php

declare(strict_types=1);

use App\Models\Plan;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Services\Subscriptions\ActivateService;

/**
 * Port of spec/services/subscriptions/activate_service_spec.rb (core scenarios,
 * minus the payment-gating branches deferred with activation rules).
 */
beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function activatablePending(array $overrides = []): Subscription
{
    $plan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 4900]);
    $customer = App\Models\Customer::factory()->create();

    return Subscription::factory()->pending()->create(array_merge([
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'external_id' => 'sub_act',
        'subscription_at' => '2024-05-15 00:00:00',
    ], $overrides));
}

it('activates a standalone pending subscription', function () {
    $subscription = activatablePending();

    $result = ActivateService::call(
        subscription: $subscription,
        timestamp: CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'),
    );

    expect($result->success())->toBeTrue()
        ->and($result->subscription->active())->toBeTrue()
        ->and($result->subscription->started_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00')
        ->and($result->subscription->activated_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00');
})->group('ledger:svc:Subscriptions.ActivateService');

it('returns the subscription untouched when already active', function () {
    $subscription = activatablePending();
    $subscription->markAsActive('2024-05-01 00:00:00');
    $subscription->save();

    $result = ActivateService::call(subscription: $subscription);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->started_at->format('Y-m-d H:i:s'))->toBe('2024-05-01 00:00:00');
});

it('terminates the previous subscription and activates the new one on upgrade', function () {
    $previousPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 2900]);
    $newPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 9900]);

    $previous = Subscription::factory()->create([
        'external_id' => 'sub_upg',
        'plan_id' => $previousPlan->id,
        'customer_id' => ($c = App\Models\Customer::factory()->create())->id,
        'organization_id' => $c->organization_id,
        'started_at' => '2024-04-01 00:00:00',
        'subscription_at' => '2024-04-01 00:00:00',
        'activated_at' => '2024-04-01 00:00:00',
    ]);

    $new = Subscription::factory()->pending()->create([
        'external_id' => 'sub_upg',
        'plan_id' => $newPlan->id,
        'customer_id' => $c->id,
        'organization_id' => $c->organization_id,
        'previous_subscription_id' => $previous->id,
        'subscription_at' => '2024-05-15 00:00:00',
    ]);

    $result = ActivateService::call(
        subscription: $new,
        timestamp: CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'),
    );

    expect($result->success())->toBeTrue()
        ->and($result->subscription->active())->toBeTrue()
        ->and($previous->fresh()->terminated())->toBeTrue()
        ->and($previous->fresh()->terminated_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00')
        ->and($new->fresh()->started_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00');
});

it('terminates the previous subscription and activates the new one on downgrade', function () {
    $previousPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 9900]);
    $newPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 2900]);

    $previous = Subscription::factory()->create([
        'external_id' => 'sub_dg_act',
        'plan_id' => $previousPlan->id,
        'customer_id' => ($c = App\Models\Customer::factory()->create())->id,
        'organization_id' => $c->organization_id,
        'started_at' => '2024-04-01 00:00:00',
        'subscription_at' => '2024-04-01 00:00:00',
        'activated_at' => '2024-04-01 00:00:00',
    ]);

    $new = Subscription::factory()->pending()->create([
        'external_id' => 'sub_dg_act',
        'plan_id' => $newPlan->id,
        'customer_id' => $c->id,
        'organization_id' => $c->organization_id,
        'previous_subscription_id' => $previous->id,
        'subscription_at' => '2024-05-15 00:00:00',
    ]);

    $result = ActivateService::call(
        subscription: $new,
        timestamp: CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'),
    );

    expect($result->success())->toBeTrue()
        ->and($result->subscription->active())->toBeTrue()
        ->and($previous->fresh()->terminated())->toBeTrue()
        ->and($new->fresh()->started_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00');
});

it('detects the upgrade branch against the previous subscription plan', function () {
    $subscription = activatablePending();
    $previous = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 1000]);
    $subscription->previous_subscription_id = Subscription::factory()->create([
        'plan_id' => $previous->id,
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'external_id' => 'other_ext',
    ])->id;
    $subscription->save();

    // yearly_amount_cents: 4900*12 > 1000*12 → upgrade.
    $service = new class($subscription, null) extends ActivateService
    {
        public function exposedUpgrade(): bool
        {
            return $this->upgrade();
        }
    };

    expect($service->exposedUpgrade())->toBeTrue();
});
