<?php

declare(strict_types=1);

use App\Services\Plans\DestroyService;

/**
 * Port of spec/services/plans/destroy_service_spec.rb — the synchronous
 * deletion: soft delete, pending_deletion reset, and subscription handling.
 *
 * Not ported from the Rails spec (dependencies do not exist yet):
 * - activity log assertion (Utils::ActivityLog),
 * - draft invoice finalization (Invoices::RefreshDraftAndFinalizeService —
 *   TODO(port) in the service),
 * - entitlements destruction (no entitlement models yet).
 */
function destroyPlan(): App\Models\Plan
{
    $organization = App\Models\Organization::factory()->create();

    return App\Models\Plan::factory()->create([
        'organization_id' => $organization->id,
        'pending_deletion' => true,
    ]);
}

it('soft deletes the plan and resets pending deletion', function (): void {
    $plan = destroyPlan();

    $result = DestroyService::call(plan: $plan);

    // Rails asserts plan.reload.pending_deletion flips true→false (discard!
    // persists dirty attributes). The M1 port runs the destroy INLINE
    // (PrepareDestroyService) where Rails uses Plans::DestroyJob, and the API
    // contract relies on the flag staying true in the DB — so the flag is
    // only reset in memory here (checked before the refresh overwrites it),
    // matching PlansControllerTest. See the NOTE(port deviation) in the
    // service.
    $pendingDeletion = $plan->pending_deletion;

    expect($result->success())->toBeTrue()
        ->and($plan->refresh()->deleted_at)->not->toBeNull()
        ->and($pendingDeletion)->toBeFalse();
})->group('ledger:svc:Plans.DestroyService');

it('returns a plan_not_found failure when the plan is nil', function (): void {
    $result = DestroyService::call(plan: null);

    expect($result->success())->toBeFalse()
        ->and($result->getError()?->getMessage())->toBe('plan_not_found');
})->group('ledger:svc:Plans.DestroyService');

it('terminates active subscriptions', function (): void {
    $plan = destroyPlan();
    $subscriptions = App\Models\Subscription::factory()->count(2)->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'status' => 'active',
    ]);

    $result = DestroyService::call(plan: $plan);

    expect($result->success())->toBeTrue();

    foreach ($subscriptions as $subscription) {
        $subscription->refresh();
        expect($subscription->status)->toBe(App\Enums\SubscriptionStatus::Terminated->value)
            ->and($subscription->terminated_at)->not->toBeNull();
    }
})->group('ledger:svc:Plans.DestroyService');

it('cancels pending subscriptions', function (): void {
    $plan = destroyPlan();
    $subscriptions = App\Models\Subscription::factory()->count(2)->create([
        'plan_id' => $plan->id,
        'organization_id' => $plan->organization_id,
        'status' => 'pending',
    ]);

    $result = DestroyService::call(plan: $plan);

    expect($result->success())->toBeTrue();

    foreach ($subscriptions as $subscription) {
        $subscription->refresh();
        expect($subscription->status)->toBe(App\Enums\SubscriptionStatus::Canceled->value)
            ->and($subscription->canceled_at)->not->toBeNull();
    }
})->group('ledger:svc:Plans.DestroyService');

it('returns the already discarded plan', function (): void {
    $plan = destroyPlan();
    $plan->delete();

    $result = DestroyService::call(plan: $plan);

    expect($result->success())->toBeTrue()
        ->and($result->plan->id)->toBe($plan->id)
        ->and($result->plan->deleted_at)->not->toBeNull();
})->group('ledger:svc:Plans.DestroyService');
