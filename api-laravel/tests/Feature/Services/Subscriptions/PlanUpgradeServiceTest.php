<?php

declare(strict_types=1);

use App\Models\Plan;
use Carbon\CarbonImmutable;
use App\Models\Subscription;
use App\Services\Subscriptions\PlanUpgradeService;
use App\Services\Subscriptions\PlanDowngradeService;

/**
 * Ports of spec/services/subscriptions/plan_upgrade_service_spec.rb and
 * plan_downgrade_service_spec.rb (core scenarios).
 */
beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-05-15 10:00:00', 'UTC'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function upgradeScenario(int $currentAmount, int $newAmount): array
{
    $customer = App\Models\Customer::factory()->create();
    $currentPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => $currentAmount]);
    $newPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => $newAmount, 'code' => 'target_plan']);

    $current = Subscription::factory()->create([
        'external_id' => 'sub_rot',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $currentPlan->id,
        'started_at' => '2024-04-01 00:00:00',
        'subscription_at' => '2024-04-01 00:00:00',
        'activated_at' => '2024-04-01 00:00:00',
        'billing_time' => 'calendar',
        'consolidate_invoice' => true,
        'purchase_order_number' => 'PO-1',
    ]);

    return [$customer, $current, $newPlan];
}

it('upgrades by creating and activating a new subscription chained to the current one', function () {
    [$customer, $current, $newPlan] = upgradeScenario(2900, 9900);

    $result = PlanUpgradeService::call(
        currentSubscription: $current,
        plan: $newPlan,
        params: ['name' => 'Upgraded plan sub'],
    );

    expect($result->success())->toBeTrue();

    $new = $result->subscription;

    expect($new->id)->not->toBe($current->id)
        ->and($new->active())->toBeTrue()
        ->and($new->previous_subscription_id)->toBe($current->id)
        ->and($new->external_id)->toBe($current->external_id)
        ->and($new->subscription_at->equalTo($current->subscription_at))->toBeTrue()
        ->and($new->billing_time)->toBe($current->billing_time)
        ->and($new->ending_at)->toBeNull()
        ->and($new->consolidate_invoice)->toBeTrue()
        ->and($new->purchase_order_number)->toBe('PO-1')
        ->and($current->fresh()->terminated())->toBeTrue();
});

it('updates the plan in place when the current subscription is still pending in the future', function () {
    $customer = App\Models\Customer::factory()->create();
    $currentPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 2900]);
    $newPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 9900]);

    $current = Subscription::factory()->pending()->create([
        'external_id' => 'sub_fut',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $currentPlan->id,
        'subscription_at' => '2024-06-01 00:00:00',
        'name' => 'Old name',
    ]);

    $result = PlanUpgradeService::call(currentSubscription: $current, plan: $newPlan, params: []);

    expect($result->success())->toBeTrue()
        ->and($result->subscription->id)->toBe($current->id)
        ->and($current->fresh()->plan_id)->toBe($newPlan->id)
        ->and($current->fresh()->name)->toBe('Old name');
});

it('updates the pending subscription name when provided on upgrade', function () {
    [$customer, $current, $newPlan] = upgradeScenario(2900, 9900);
    // Make the current subscription pending-in-the-future.
    $current->status = 'pending';
    $current->started_at = null;
    $current->activated_at = null;
    $current->subscription_at = '2024-06-01 00:00:00';
    $current->save();

    $result = PlanUpgradeService::call(
        currentSubscription: $current,
        plan: $newPlan,
        params: ['name' => 'Fresh name'],
    );

    expect($result->success())->toBeTrue()
        ->and($current->fresh()->name)->toBe('Fresh name')
        ->and($current->fresh()->plan_id)->toBe($newPlan->id);
});

it('cancels an existing pending scheduled change before creating the upgrade', function () {
    [$customer, $current, $newPlan] = upgradeScenario(2900, 9900);

    $scheduled = Subscription::factory()->pending()->create([
        'external_id' => $current->external_id,
        'customer_id' => $current->customer_id,
        'organization_id' => $current->organization_id,
        'plan_id' => $current->plan_id,
        'previous_subscription_id' => $current->id,
        'subscription_at' => '2024-06-01 00:00:00',
    ]);

    $result = PlanUpgradeService::call(currentSubscription: $current, plan: $newPlan, params: []);

    expect($result->success())->toBeTrue()
        ->and($scheduled->fresh()->canceled())->toBeTrue();
});

// -- PlanDowngradeService ---------------------------------------------------------

it('downgrades by scheduling a pending next subscription while the current stays active', function () {
    [$customer, $current, $newPlan] = upgradeScenario(9900, 2900);

    $result = PlanDowngradeService::call(
        customer: $customer,
        currentSubscription: $current,
        plan: $newPlan,
        params: ['name' => 'Downgrade sub'],
    );

    expect($result->success())->toBeTrue()
        ->and($result->subscription->id)->toBe($current->id);

    $next = $current->nextSubscription();

    expect($next)->not->toBeNull()
        ->and($next->pending())->toBeTrue()
        ->and($next->previous_subscription_id)->toBe($current->id)
        ->and($next->external_id)->toBe($current->external_id)
        ->and($next->plan_id)->toBe($newPlan->id)
        ->and($next->name)->toBe('Downgrade sub')
        ->and($next->subscription_at->equalTo($current->subscription_at))->toBeTrue()
        ->and($next->billing_time)->toBe($current->billing_time)
        ->and($current->fresh()->active())->toBeTrue();
});

it('updates the plan in place when downgrading a still-pending future subscription', function () {
    $customer = App\Models\Customer::factory()->create();
    $currentPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 9900]);
    $newPlan = Plan::factory()->create(['interval' => 'monthly', 'amount_cents' => 2900]);

    $current = Subscription::factory()->pending()->create([
        'external_id' => 'sub_fut_dg',
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
        'plan_id' => $currentPlan->id,
        'subscription_at' => '2024-06-01 00:00:00',
        'name' => 'Old',
    ]);

    $result = PlanDowngradeService::call(
        customer: $customer,
        currentSubscription: $current,
        plan: $newPlan,
        params: ['name' => 'New'],
    );

    expect($result->success())->toBeTrue()
        ->and($result->subscription->id)->toBe($current->id)
        ->and($current->fresh()->plan_id)->toBe($newPlan->id)
        ->and($current->fresh()->name)->toBe('New');
});

it('cancels an existing pending downgrade when downgrading again', function () {
    [$customer, $current, $newPlan] = upgradeScenario(9900, 2900);

    $scheduled = Subscription::factory()->pending()->create([
        'external_id' => $current->external_id,
        'customer_id' => $current->customer_id,
        'organization_id' => $current->organization_id,
        'plan_id' => $current->plan_id,
        'previous_subscription_id' => $current->id,
        'subscription_at' => '2024-06-01 00:00:00',
    ]);

    $result = PlanDowngradeService::call(
        customer: $customer,
        currentSubscription: $current,
        plan: $newPlan,
        params: [],
    );

    expect($result->success())->toBeTrue()
        ->and($scheduled->fresh()->canceled())->toBeTrue();

    $latest = $current->nextSubscription();
    expect($latest)->not->toBeNull()
        ->and($latest->id)->not->toBe($scheduled->id);
});
