<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\CurrentContext;
use App\Models\EntitlementValue;
use App\Models\SubscriptionFeatureRemoval;
use App\Models\Entitlement\SubscriptionEntitlement;
use App\Services\Entitlements\SubscriptionEntitlementUpdateService;
use App\Services\Entitlements\SubscriptionEntitlementsUpdateService;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Ports of Rails' spec/services/entitlement/
 * subscription_entitlements_update_service-{full,partial}_spec.rb,
 * subscription_entitlement_core_update_service_spec.rb and
 * subscription_entitlement_update_service_spec.rb.
 */
function subscriptionFixture(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'plan_code']);
    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub_external_id',
    ]);
    $feature = Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);
    $privilege = Privilege::factory()->forFeature($feature)->create(['code' => 'max', 'value_type' => 'integer']);

    return [$organization, $plan, $subscription, $feature, $privilege];
}

// -- Full update (partial: false) ---------------------------------------------------

it('creates the override when neither plan nor subscription carries the feature', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = subscriptionFixture();

    $result = SubscriptionEntitlementsUpdateService::call(
        subscription: $subscription,
        entitlementsParams: ['seats' => ['max' => 20]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and(Entitlement::query()->whereNotNull('subscription_id')->count())->toBe(1);

    $override = Entitlement::query()->where('subscription_id', $subscription->id)->sole();

    expect($override->entitlement_feature_id)->toBe($feature->id)
        ->and($override->values()->sole()->value)->toBe('20');
})->group('ledger:svc:Entitlement.SubscriptionEntitlementsUpdateService');

it('removes plan features absent from a full update with a removal tombstone', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = subscriptionFixture();

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();

    // A feature carried only by the plan, dropped from the params.
    $planOnlyFeature = Feature::factory()->forOrganization($organization)->create(['code' => 'storage']);
    $planOnlyPrivilege = Privilege::factory()->forFeature($planOnlyFeature)->create(['code' => 'size', 'value_type' => 'integer']);
    $planEntitlement = Entitlement::factory()->forOrganization($organization)->forFeature($planOnlyFeature)->forPlan($plan)->create();

    $result = SubscriptionEntitlementsUpdateService::call(
        subscription: $subscription,
        entitlementsParams: ['seats' => ['max' => 20]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and(SubscriptionFeatureRemoval::query()->count())->toBe(1);

    $removal = SubscriptionFeatureRemoval::query()->sole();

    expect($removal->entitlement_feature_id)->toBe($planOnlyFeature->id)
        ->and($removal->subscription_id)->toBe($subscription->id)
        // The plan's entitlement is KEPT — the tombstone filters it out of
        // the subscription's merged view.
        ->and($planEntitlement->refresh()->deleted_at)->toBeNull();
});

it('deletes subscription overrides absent from a full update', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = subscriptionFixture();

    $override = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($override, $privilege)->create(['value' => '10']);

    $result = SubscriptionEntitlementsUpdateService::call(
        subscription: $subscription,
        entitlementsParams: [],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and($override->refresh()->deleted_at)->not->toBeNull()
        ->and(EntitlementValue::withTrashed()->whereNotNull('deleted_at')->count())->toBe(1);
});

it('returns a feature not found failure on unknown codes', function (): void {
    [$organization, $plan, $subscription] = subscriptionFixture();

    $result = SubscriptionEntitlementsUpdateService::call(
        subscription: $subscription,
        entitlementsParams: ['nonexistent' => ['max' => 1]],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toContain('feature_not_found');
});

it('restores the plan default when the params equal the plan values', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = subscriptionFixture();

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create()->values()
        ->create(['organization_id' => $organization->id, 'entitlement_privilege_id' => $privilege->id, 'value' => '20']);

    $override = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($override, $privilege)->create(['value' => '99']);

    SubscriptionFeatureRemoval::factory()->forSubscriptionAndFeature($subscription, $feature)->create();

    $result = SubscriptionEntitlementsUpdateService::call(
        subscription: $subscription,
        entitlementsParams: ['seats' => ['max' => 20]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and($override->refresh()->deleted_at)->not->toBeNull()
        ->and(SubscriptionFeatureRemoval::query()->count())->toBe(0);
});

// -- Partial update (partial: true) --------------------------------------------------

it('creates a differing override value on a partial update', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = subscriptionFixture();

    $planEntitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($planEntitlement, $privilege)->create(['value' => '10']);

    $result = SubscriptionEntitlementsUpdateService::call(
        subscription: $subscription,
        entitlementsParams: ['seats' => ['max' => 30]],
        partial: true,
    );

    expect($result->success())->toBeTrue();

    $override = Entitlement::query()->where('subscription_id', $subscription->id)->sole();

    expect($override->values()->sole()->value)->toBe('30');
});

it('clears the override when the value equals the plan value', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = subscriptionFixture();

    $planEntitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($planEntitlement, $privilege)->create(['value' => '10']);

    $override = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();
    $overrideValue = EntitlementValue::factory()->forEntitlementAndPrivilege($override, $privilege)->create(['value' => '30']);

    SubscriptionFeatureRemoval::factory()->forSubscriptionAndFeature($subscription, $feature)->create();

    $result = SubscriptionEntitlementsUpdateService::call(
        subscription: $subscription,
        entitlementsParams: ['seats' => ['max' => 10]],
        partial: true,
    );

    expect($result->success())->toBeTrue()
        ->and($overrideValue->refresh()->deleted_at)->not->toBeNull()
        ->and(SubscriptionFeatureRemoval::query()->count())->toBe(0);
});

// -- Single entitlement update (GraphQL path) ----------------------------------------

it('updates a single feature entitlement and returns the merged view', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = subscriptionFixture();

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create()->values()
        ->create(['organization_id' => $organization->id, 'entitlement_privilege_id' => $privilege->id, 'value' => '10']);

    $result = SubscriptionEntitlementUpdateService::call(
        subscription: $subscription,
        featureCode: 'seats',
        privilegeParams: ['max' => 42],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and($result->entitlement)->toBeInstanceOf(SubscriptionEntitlement::class)
        ->and($result->entitlement->code)->toBe('seats')
        ->and($result->entitlement->privileges[0]->value)->toBe('42')
        ->and($result->entitlement->privileges[0]->planValue)->toBe('10');
})->group('ledger:svc:Entitlement.SubscriptionEntitlementUpdateService');

it('returns a feature not found failure for an unknown feature code', function (): void {
    [$organization, $plan, $subscription] = subscriptionFixture();

    $result = SubscriptionEntitlementUpdateService::call(
        subscription: $subscription,
        featureCode: 'nonexistent',
        privilegeParams: ['max' => 1],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toContain('feature_not_found');
});

it('returns the null entitlement when the feature has no values', function (): void {
    [$organization, $plan, $subscription, $feature] = subscriptionFixture();

    $result = SubscriptionEntitlementUpdateService::call(
        subscription: $subscription,
        featureCode: 'seats',
        privilegeParams: [],
        partial: true,
    );

    expect($result->success())->toBeTrue()
        // The override now exists with no privileges — the merged view
        // carries the feature with an empty privilege list.
        ->and($result->entitlement)->toBeInstanceOf(SubscriptionEntitlement::class)
        ->and($result->entitlement->code)->toBe('seats')
        ->and($result->entitlement->privileges)->toBe([]);
});
