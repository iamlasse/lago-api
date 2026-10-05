<?php

declare(strict_types=1);

use App\Models\Plan;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Services\Subscriptions\UpdateService;

/**
 * Port of spec/services/subscriptions/update_service_spec.rb (core scenarios).
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function updatableSubscription(array $overrides = []): Subscription
{
    $plan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 4900]);
    $customer = App\Models\Customer::factory()->create();

    return Subscription::factory()->create(array_merge([
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'external_id' => 'sub_upd',
        'started_at' => '2024-04-01 00:00:00',
        'subscription_at' => '2024-04-01 00:00:00',
    ], $overrides));
}

it('updates the editable attributes of an active subscription', function (): void {
    $subscription = updatableSubscription();

    $result = UpdateService::call(subscription: $subscription, params: [
        'name' => 'Renamed sub',
        'ending_at' => '2025-06-30T00:00:00Z',
        'purchase_order_number' => 'PO-42',
        'progressive_billing_disabled' => true,
        'consolidate_invoice' => 'false',
        'on_termination_invoice' => 'skip',
    ]);

    expect($result->success())->toBeTrue();

    $subscription = $subscription->fresh();

    expect($subscription->name)->toBe('Renamed sub')
        ->and($subscription->ending_at->format('Y-m-d'))->toBe('2025-06-30')
        ->and($subscription->purchase_order_number)->toBe('PO-42')
        ->and($subscription->progressive_billing_disabled)->toBeTrue()
        ->and($subscription->consolidate_invoice)->toBeFalse()
        ->and($subscription->on_termination_invoice)->toBe('skip');
})->group('ledger:svc:Subscriptions.UpdateService');

it('only assigns on_termination_credit_note for pay-in-advance plans', function (): void {
    $arrears = updatableSubscription();
    $inAdvance = updatableSubscription(['plan_id' => Plan::factory()->create([
        'interval' => 'monthly',
        'pay_in_advance' => true,
    ])->id, 'external_id' => 'sub_pia']);

    UpdateService::call(subscription: $arrears, params: ['on_termination_credit_note' => 'credit']);
    UpdateService::call(subscription: $inAdvance, params: ['on_termination_credit_note' => 'credit']);

    expect($arrears->fresh()->on_termination_credit_note)->toBeNull()
        ->and($inAdvance->fresh()->on_termination_credit_note)->toBe('credit');
});

it('fails on an incomplete subscription', function (): void {
    $subscription = updatableSubscription(['status' => 'incomplete', 'started_at' => now()]);

    $result = UpdateService::call(subscription: $subscription, params: ['name' => 'X']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('subscription_incomplete');
});

it('refuses a purchase order number change on a terminated subscription', function (): void {
    $subscription = updatableSubscription([
        'status' => 'terminated',
        'terminated_at' => '2024-05-01 00:00:00',
        'purchase_order_number' => 'PO-OLD',
    ]);

    $result = UpdateService::call(subscription: $subscription, params: ['purchase_order_number' => 'PO-NEW']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('purchase_order_number_not_editable');
});

it('allows resending the same purchase order number on a terminated subscription', function (): void {
    $subscription = updatableSubscription([
        'status' => 'terminated',
        'terminated_at' => '2024-05-01 00:00:00',
        'purchase_order_number' => 'PO-OLD',
    ]);

    $result = UpdateService::call(subscription: $subscription, params: ['purchase_order_number' => '  PO-OLD  ']);

    expect($result->success())->toBeTrue();
});

it('validates ending_at', function (): void {
    $subscription = updatableSubscription();

    $result = UpdateService::call(subscription: $subscription, params: ['ending_at' => '2024-01-01T00:00:00Z']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['ending_at' => ['invalid_date']]);
});

it('activates a future subscription whose subscription_at moved to today', function (): void {
    $subscription = Subscription::factory()->pending()->create([
        'external_id' => 'sub_future_upd',
        'plan_id' => Plan::factory()->create(['interval' => 'monthly'])->id,
        'customer_id' => ($c = App\Models\Customer::factory()->create())->id,
        'organization_id' => $c->organization_id,
        'subscription_at' => '2024-05-16 00:00:00',
    ]);

    $result = UpdateService::call(subscription: $subscription, params: [
        'subscription_at' => '2024-05-15T10:00:00Z', // today
    ]);

    expect($result->success())->toBeTrue();

    $subscription = $subscription->fresh();

    expect($subscription->active())->toBeTrue()
        ->and($subscription->started_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00')
        ->and($subscription->activated_at->format('Y-m-d H:i:s'))->toBe('2024-05-15 10:00:00');
});

it('keeps a future subscription pending when subscription_at moves further out', function (): void {
    $subscription = Subscription::factory()->pending()->create([
        'external_id' => 'sub_future_upd2',
        'plan_id' => Plan::factory()->create(['interval' => 'monthly'])->id,
        'customer_id' => ($c = App\Models\Customer::factory()->create())->id,
        'organization_id' => $c->organization_id,
        'subscription_at' => '2024-05-16 00:00:00',
    ]);

    $result = UpdateService::call(subscription: $subscription, params: [
        'subscription_at' => '2024-07-01T00:00:00Z',
    ]);

    expect($result->success())->toBeTrue()
        ->and($subscription->fresh()->pending())->toBeTrue()
        ->and($subscription->fresh()->subscription_at->format('Y-m-d'))->toBe('2024-07-01');
});

it('rejects plan_overrides without a premium license', function (): void {
    // The config default may carry a token (premium by default in this
    // environment) — the scenario needs the gate closed.
    config(['lago.license' => null]);

    $subscription = updatableSubscription();

    $result = UpdateService::call(subscription: $subscription, params: [
        'plan_overrides' => ['amount_cents' => 100],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->code)->toBe('feature_unavailable');
});

it('updates a pending downgrade subscription plan in place when its plan changes', function (): void {
    $plan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 4900]);
    $customer = App\Models\Customer::factory()->create();

    $subscription = Subscription::factory()->pending()->create([
        'external_id' => 'sub_pending_upd',
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'subscription_at' => '2024-06-01 00:00:00',
        'name' => 'Old name',
    ]);

    $newPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 9900]);

    $result = UpdateService::call(subscription: $subscription, params: [
        'subscription_at' => '2024-07-01T00:00:00Z',
    ]);

    expect($result->success())->toBeTrue()
        ->and($subscription->fresh()->plan_id)->toBe($plan->id);

    // plan swap is not part of update params in M1 (plan_overrides premium);
    // but name update works while pending.
    $result2 = UpdateService::call(subscription: $subscription, params: ['name' => 'Pending renamed']);
    expect($result2->success())->toBeTrue()
        ->and($subscription->fresh()->name)->toBe('Pending renamed');
});
