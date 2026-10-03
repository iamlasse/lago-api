<?php

declare(strict_types=1);

use App\Services\Plans\UpdateAmountService;

/**
 * Port of spec/services/plans/update_amount_service_spec.rb — the optimistic
 * child-plan amount update (compare-and-swap on expected_amount_cents).
 *
 * Not ported from the Rails spec: the pending-subscription upgrade branch
 * calls Subscriptions::PlanUpgradeService, which does not exist yet
 * (TODO(port) in the service) — the port leaves pending subscriptions
 * untouched where Rails would upgrade them.
 */
function updateAmountPlan(int $amountCents = 111): App\Models\Plan
{
    $organization = App\Models\Organization::factory()->create();

    return App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => $amountCents,
    ]);
}

it('updates the plan amount when the expected amount matches', function (): void {
    $plan = updateAmountPlan(111);

    $result = UpdateAmountService::call(plan: $plan, amountCents: 222, expectedAmountCents: 111);

    expect($result->success())->toBeTrue()
        ->and($plan->refresh()->amount_cents)->toBe(222);
})->group('ledger:svc:Plans.UpdateAmountService');

it('returns a plan_not_found failure when the plan is nil', function (): void {
    $result = UpdateAmountService::call(plan: null, amountCents: 222, expectedAmountCents: 111);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->getMessage())->toBe('plan_not_found');
})->group('ledger:svc:Plans.UpdateAmountService');

it('does not update the plan when the expected amount does not match', function (): void {
    $plan = updateAmountPlan(111);

    $result = UpdateAmountService::call(plan: $plan, amountCents: 222, expectedAmountCents: 10);

    expect($result->success())->toBeTrue()
        ->and($plan->refresh()->amount_cents)->toBe(111);
})->group('ledger:svc:Plans.UpdateAmountService');

it('leaves pending subscriptions untouched (upgrade service not ported yet)', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $plan = App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 111,
        'interval' => 'monthly',
    ]);
    $previous = App\Models\Subscription::factory()->create([
        'customer_id' => App\Models\Customer::factory()->create(['organization_id' => $organization->id])->id,
        'plan_id' => App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'amount_cents' => 111])->id,
        'organization_id' => $organization->id,
        'status' => 'active',
    ]);
    $pending = App\Models\Subscription::factory()->create([
        'customer_id' => $previous->customer_id,
        'plan_id' => $plan->id,
        'organization_id' => $organization->id,
        'status' => 'pending',
        'previous_subscription_id' => $previous->id,
    ]);

    $result = UpdateAmountService::call(plan: $plan, amountCents: 222, expectedAmountCents: 111);

    expect($result->success())->toBeTrue()
        ->and($plan->refresh()->amount_cents)->toBe(222)
        // Rails would upgrade the pending subscription to the new plan here.
        ->and($pending->refresh()->status)->toBe(App\Enums\SubscriptionStatus::Pending->value);
})->group('ledger:svc:Plans.UpdateAmountService');
