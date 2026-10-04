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
use App\Services\Entitlements\SubscriptionFeatureRemoveService;
use App\Services\Entitlements\SubscriptionFeaturePrivilegeRemoveService;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Ports of Rails' spec/services/entitlement/
 * subscription_feature_remove_service_spec.rb and
 * subscription_feature_privilege_remove_service_spec.rb.
 */
function removalFixture(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
    ]);
    $feature = Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);
    $privilege = Privilege::factory()->forFeature($feature)->create(['code' => 'max', 'value_type' => 'integer']);

    return [$organization, $plan, $subscription, $feature, $privilege];
}

it('discards the subscription override and records the tombstone when inherited', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = removalFixture();

    $planEntitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    $override = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();
    $value = EntitlementValue::factory()->forEntitlementAndPrivilege($override, $privilege)->create(['value' => '10']);

    $result = SubscriptionFeatureRemoveService::call(
        subscription: $subscription,
        featureCode: 'seats',
    );

    expect($result->success())->toBeTrue()
        ->and($result->featureCode)->toBe('seats')
        ->and($override->refresh()->deleted_at)->not->toBeNull()
        ->and($value->refresh()->deleted_at)->not->toBeNull()
        ->and($planEntitlement->refresh()->deleted_at)->toBeNull();

    $removal = SubscriptionFeatureRemoval::query()->sole();

    expect($removal->entitlement_feature_id)->toBe($feature->id)
        ->and($removal->subscription_id)->toBe($subscription->id);
})->group('ledger:svc:Entitlements.SubscriptionFeatureRemoveService');

it('creates no tombstone when the feature is not on the plan', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = removalFixture();

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();

    $result = SubscriptionFeatureRemoveService::call(
        subscription: $subscription,
        featureCode: 'seats',
    );

    expect($result->success())->toBeTrue()
        ->and(SubscriptionFeatureRemoval::query()->count())->toBe(0);
});

it('is idempotent against repeated removals', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = removalFixture();

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();

    SubscriptionFeatureRemoveService::call(subscription: $subscription, featureCode: 'seats');
    SubscriptionFeatureRemoveService::call(subscription: $subscription, featureCode: 'seats');

    expect(SubscriptionFeatureRemoval::query()->count())->toBe(1);
});

it('returns a feature not found failure', function (): void {
    [$organization, $plan, $subscription] = removalFixture();

    $result = SubscriptionFeatureRemoveService::call(
        subscription: $subscription,
        featureCode: 'nonexistent',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->resource)->toBe('feature');
});

it('discards a single override value and records the privilege tombstone', function (): void {
    [$organization, $plan, $subscription, $feature, $privilege] = removalFixture();

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    $override = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();
    $value = EntitlementValue::factory()->forEntitlementAndPrivilege($override, $privilege)->create(['value' => '10']);

    $result = SubscriptionFeaturePrivilegeRemoveService::call(
        subscription: $subscription,
        featureCode: 'seats',
        privilegeCode: 'max',
    );

    expect($result->success())->toBeTrue()
        ->and($result->featureCode)->toBe('seats')
        ->and($result->privilegeCode)->toBe('max')
        ->and($value->refresh()->deleted_at)->not->toBeNull()
        // The override row itself stays — only the one value goes.
        ->and($override->refresh()->deleted_at)->toBeNull();

    $removal = SubscriptionFeatureRemoval::query()->sole();

    expect($removal->entitlement_privilege_id)->toBe($privilege->id);
});

it('returns a privilege not found failure', function (): void {
    [$organization, $plan, $subscription, $feature] = removalFixture();

    $result = SubscriptionFeaturePrivilegeRemoveService::call(
        subscription: $subscription,
        featureCode: 'seats',
        privilegeCode: 'nonexistent',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->resource)->toBe('privilege');
});
