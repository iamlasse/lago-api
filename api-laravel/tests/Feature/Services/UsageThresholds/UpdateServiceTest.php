<?php

declare(strict_types=1);

uses()->group('ledger:svc:UsageThresholds.UpdateService',
    'ledger:svc:UsageThresholds.OverrideService',
    'ledger:svc:Subscriptions.UpdateUsageThresholdsService');

use App\Models\Plan;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\UsageThreshold;
use Illuminate\Support\Facades\Queue;
use App\Services\UsageThresholds\UpdateService;
use App\Services\UsageThresholds\OverrideService;
use App\Services\Subscriptions\UpdateUsageThresholdsService;

/**
 * Port of spec/services/usage_thresholds/update_service_spec.rb and
 * override_service_spec.rb (core scenarios) + the subscription attach path.
 */
function thresholdPlan(): Plan
{
    return Plan::factory()->create();
}

it('creates thresholds in a full update', function (): void {
    $plan = thresholdPlan();

    $result = UpdateService::call(
        model: $plan,
        usageThresholdsParams: [
            ['threshold_display_name' => 'First', 'amount_cents' => 10000, 'recurring' => false],
            ['threshold_display_name' => 'Every', 'amount_cents' => 50000, 'recurring' => true],
        ],
        partial: false,
    );

    expect($result->success())->toBeTrue();

    $thresholds = $plan->usageThresholds()->get();

    expect($thresholds)->toHaveCount(2)
        ->and($thresholds->where('recurring', true)->first()->amount_cents)->toBe(50000)
        ->and($thresholds->where('recurring', true)->first()->threshold_display_name)->toBe('Every');
});

it('replaces thresholds on a full update and preserves the recurring row', function (): void {
    $plan = thresholdPlan();

    UpdateService::call(model: $plan, usageThresholdsParams: [
        ['amount_cents' => 10000, 'recurring' => false],
        ['amount_cents' => 50000, 'recurring' => true],
    ], partial: false);

    UpdateService::call(model: $plan, usageThresholdsParams: [
        ['threshold_display_name' => 'Renamed', 'amount_cents' => 10000, 'recurring' => false],
        ['amount_cents' => 60000, 'recurring' => true],
    ], partial: false);

    $thresholds = $plan->usageThresholds()->get();

    expect($thresholds)->toHaveCount(2)
        ->and($thresholds->where('amount_cents', 60000)->first()->recurring)->toBeTrue()
        ->and($thresholds->where('amount_cents', 10000)->first()->threshold_display_name)->toBe('Renamed')
        ->and(UsageThreshold::withTrashed()->where('plan_id', $plan->id)->count())->toBe(4);
});

it('validates the matrix: missing amount, duplicates and multiple recurring', function (): void {
    $plan = thresholdPlan();

    $missing = UpdateService::call(model: $plan, usageThresholdsParams: [
        ['threshold_display_name' => 'x'],
    ], partial: false);

    expect($missing->success())->toBeFalse()
        ->and($missing->getError()->getMessage())->toContain('missing_amount_cents');

    $duplicated = UpdateService::call(model: $plan, usageThresholdsParams: [
        ['amount_cents' => 10000], ['amount_cents' => 10000],
    ], partial: false);

    expect($duplicated->success())->toBeFalse()
        ->and($duplicated->getError()->getMessage())->toContain('duplicated_values');

    $multipleRecurring = UpdateService::call(model: $plan, usageThresholdsParams: [
        ['amount_cents' => 10000, 'recurring' => true],
        ['amount_cents' => 20000, 'recurring' => true],
    ], partial: false);

    expect($multipleRecurring->success())->toBeFalse()
        ->and($multipleRecurring->getError()->getMessage())->toContain('multiple_recurring_thresholds');

    // Empty params on a FULL update still validate; a PARTIAL update no-ops.
    $partialEmpty = UpdateService::call(model: $plan, usageThresholdsParams: [], partial: true);

    expect($partialEmpty->success())->toBeTrue();
});

it('only renames a non-recurring threshold on a partial update', function (): void {
    $plan = thresholdPlan();

    UpdateService::call(model: $plan, usageThresholdsParams: [
        ['amount_cents' => 10000, 'recurring' => false],
    ], partial: false);

    $result = UpdateService::call(model: $plan, usageThresholdsParams: [
        ['threshold_display_name' => 'Renamed', 'amount_cents' => 10000, 'recurring' => false],
    ], partial: true);

    expect($result->success())->toBeTrue()
        ->and($plan->usageThresholds()->count())->toBe(1)
        ->and($plan->usageThresholds()->first()->threshold_display_name)->toBe('Renamed');
});

it('seeds an override plan through OverrideService', function (): void {
    $organization = Organization::factory()->create();
    $newPlan = Plan::factory()->create(['organization_id' => $organization->id]);

    $result = OverrideService::call(
        usageThresholdsParams: [
            ['amount_cents' => 1000, 'recurring' => false],
            ['amount_cents' => 5000, 'recurring' => true],
        ],
        newPlan: $newPlan,
    );

    expect($result->success())->toBeTrue()
        ->and($result->usage_thresholds)->toHaveCount(2)
        ->and($newPlan->usageThresholds()->where('recurring', true)->first()->amount_cents)->toBe(5000);
});

it('attaches thresholds to a subscription and flags the lifetime usage', function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);

    $organization = Organization::factory()->create(['premium_integrations' => ['progressive_billing']]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub-1',
        'status' => 1,
    ]);

    $lifetimeUsage = $subscription->createLifetimeUsage();

    $result = UpdateUsageThresholdsService::callBang(
        subscription: $subscription,
        usageThresholdsParams: [
            ['amount_cents' => 10000, 'recurring' => false],
            ['amount_cents' => 50000, 'recurring' => true],
        ],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and($subscription->usageThresholds()->count())->toBe(2)
        ->and($subscription->fresh()->lifetimeUsage->recalculate_invoiced_usage)->toBeTrue()
        ->and($subscription->hasProgressiveBilling())->toBeTrue()
        ->and($subscription->applicableUsageThresholds()->count())->toBe(2);
});

it('answers no applicable thresholds when progressive billing is disabled', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    Plan::factory()->create(['organization_id' => $organization->id, 'parent_id' => $plan->id]);

    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'progressive_billing_disabled' => true,
        'status' => 1,
    ]);

    UpdateService::callBang(model: $plan, usageThresholdsParams: [
        ['amount_cents' => 10000, 'recurring' => false],
    ], partial: false);

    expect($subscription->applicableUsageThresholds())->toHaveCount(0)
        ->and($subscription->hasProgressiveBilling())->toBeFalse();
});
