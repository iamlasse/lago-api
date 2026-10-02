<?php

declare(strict_types=1);

use App\Models\Plan;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Serializers\V1\SubscriptionSerializer;

/**
 * Port of spec/serializers/v1/subscription_serializer_spec.rb — payload shape
 * and value formatting.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function serializableSubscription(array $overrides = []): Subscription
{
    $plan = Plan::factory()->create([
        'interval' => 'monthly',
        'amount_cents' => 4900,
        'amount_currency' => 'EUR',
        'code' => 'pro_plan',
    ]);
    $customer = App\Models\Customer::factory()->create(['external_id' => 'cust_ser']);

    return Subscription::factory()->create(array_merge([
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'external_id' => 'sub_ser',
        'name' => 'Serialized sub',
        'started_at' => '2024-04-01 12:34:56',
        'activated_at' => '2024-04-01 12:34:56',
        'subscription_at' => '2024-04-01 00:00:00',
        'billing_time' => 'anniversary',
        'status' => 'active',
        'purchase_order_number' => 'PO-7',
        'progressive_billing_disabled' => true,
    ], $overrides));
}

it('serializes the core subscription payload', function (): void {
    $subscription = serializableSubscription();

    $payload = (new SubscriptionSerializer($subscription))->serialize();

    expect($payload['lago_id'])->toBe($subscription->id)
        ->and($payload['external_id'])->toBe('sub_ser')
        ->and($payload['lago_customer_id'])->toBe($subscription->customer_id)
        ->and($payload['external_customer_id'])->toBe('cust_ser')
        ->and($payload['name'])->toBe('Serialized sub')
        ->and($payload['plan_code'])->toBe('pro_plan')
        ->and($payload['plan_amount_cents'])->toBe(4900)
        ->and($payload['plan_amount_currency'])->toBe('EUR')
        ->and($payload['status'])->toBe('active')
        ->and($payload['billing_time'])->toBe('anniversary')
        ->and($payload['subscription_at'])->toBe('2024-04-01T00:00:00Z')
        ->and($payload['started_at'])->toBe('2024-04-01T12:34:56.000Z')
        ->and($payload['created_at'])->not->toBeNull()
        ->and($payload['previous_plan_code'])->toBeNull()
        ->and($payload['next_plan_code'])->toBeNull()
        ->and($payload['downgrade_plan_date'])->toBeNull()
        ->and($payload['on_termination_credit_note'])->toBeNull()
        ->and($payload['on_termination_invoice'])->toBe('generate')
        ->and($payload['progressive_billing_disabled'])->toBeTrue()
        ->and($payload['consolidate_invoice'])->toBeTrue()
        ->and($payload['purchase_order_number'])->toBe('PO-7')
        ->and($payload['cancellation_reason'])->toBeNull()
        ->and($payload['activated_at'])->toBe('2024-04-01T12:34:56Z');
});

it('serializes the payment method fragment', function (): void {
    $subscription = serializableSubscription();

    $payload = (new SubscriptionSerializer($subscription))->serialize();

    expect($payload['payment_method'])->toBe([
        'payment_method_id' => null,
        'payment_method_type' => 'provider',
    ]);
});

it('serializes the current billing period from the dates service', function (): void {
    $subscription = serializableSubscription();

    $payload = (new SubscriptionSerializer($subscription))->serialize();

    // Anniversary monthly plan, subscription day = 1, billing mid-May 2024
    // (current_usage: true bounds the period by the billing date): the current
    // period runs May 1 → May 31.
    expect($payload['current_billing_period_started_at'])->toBe('2024-05-01T00:00:00+00:00')
        ->and($payload['current_billing_period_ending_at'])->toBe('2024-05-31T23:59:59+00:00');
});

it('serializes previous and next plan codes across an upgrade chain', function (): void {
    $subscription = serializableSubscription();

    $oldPlan = Plan::factory()->create(['code' => 'old_plan', 'amount_cents' => 100]);
    $previous = Subscription::factory()->create([
        'external_id' => 'sub_ser',
        'customer_id' => $subscription->customer_id,
        'organization_id' => $subscription->organization_id,
        'plan_id' => $oldPlan->id,
        'status' => 'terminated',
    ]);

    $subscription->previous_subscription_id = $previous->id;
    $subscription->save();

    $payload = (new SubscriptionSerializer($subscription))->serialize();

    expect($payload['previous_plan_code'])->toBe('old_plan');
});

it('emits the includes-driven fragments', function (): void {
    $subscription = serializableSubscription();

    $payload = (new SubscriptionSerializer($subscription, ['includes' => ['customer', 'plan', 'entitlements']]))->serialize();

    expect($payload['customer'])->toBeArray()
        ->and($payload['customer']['external_id'])->toBe('cust_ser')
        ->and($payload['plan'])->toBeArray()
        ->and($payload['plan']['code'])->toBe('pro_plan')
        ->and($payload['entitlements'])->toBe([])
        ->and($payload['activation_rules'])->toBe([]);
});
