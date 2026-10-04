<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Models\EntitlementValue;
use App\Services\Entitlements\PlanEntitlementsUpdateService;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Ports of Rails' spec/services/entitlement/
 * plan_entitlements_update_service-full_spec.rb and
 * plan_entitlements_update_service-partial_spec.rb.
 */
function planEntitlementFixture(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $feature = Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);
    $privilege = Privilege::factory()->forFeature($feature)->create(['code' => 'max', 'value_type' => 'integer']);

    return [$organization, $plan, $feature, $privilege];
}

// -- Full update (partial: false) -------------------------------------------------

it('creates entitlements and values for the plan', function (): void {
    [$organization, $plan, $feature, $privilege] = planEntitlementFixture();

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['max' => 25]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(EntitlementValue::query()->count())->toBe(1)
        ->and($result->entitlements)->toHaveCount(1);

    $entitlement = Entitlement::query()->where('plan_id', $plan->id)->sole();

    expect($entitlement->entitlement_feature_id)->toBe($feature->id);

    $value = $entitlement->values()->sole();

    expect($value->entitlement_privilege_id)->toBe($privilege->id)
        ->and($value->value)->toBe('25');
})->group('ledger:svc:Entitlements.PlanEntitlementsUpdateService');

it('replaces existing entitlements on a full update', function (): void {
    [$organization, $plan, $feature, $privilege] = planEntitlementFixture();

    // Rails: the pre-existing entitlement carries its own feature (a
    // different one than the params').
    $otherFeature = Feature::factory()->forOrganization($organization)->create();
    $existing = Entitlement::factory()->forOrganization($organization)->forFeature($otherFeature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($existing, $privilege)->create(['value' => '10']);

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['max' => 25]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and($existing->refresh()->deleted_at)->not->toBeNull()
        ->and(EntitlementValue::withTrashed()->find($existing->values()->withTrashed()->sole()->id)->deleted_at)
        ->not->toBeNull();

    $newEntitlement = Entitlement::query()->where('plan_id', $plan->id)->sole();

    expect($newEntitlement->id)->not->toBe($existing->id)
        ->and($newEntitlement->values()->sole()->value)->toBe('25');
});

it('creates values for every privilege', function (): void {
    [$organization, $plan, $feature, $privilege] = planEntitlementFixture();

    $privilege2 = Privilege::factory()->forFeature($feature)->create(['code' => 'max_admins', 'value_type' => 'integer']);

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['max' => 25, 'max_admins' => 5]],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and(EntitlementValue::query()->count())->toBe(2);

    $values = Entitlement::query()->where('plan_id', $plan->id)->sole()->values()->get()->keyBy('entitlement_privilege_id');

    expect($values[$privilege->id]->value)->toBe('25')
        ->and($values[$privilege2->id]->value)->toBe('5');
});

it('returns a feature not found failure', function (): void {
    [$organization, $plan] = planEntitlementFixture();

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['nonexistent_feature' => ['max' => 25]],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toContain('feature_not_found');
});

it('returns a privilege not found failure', function (): void {
    [$organization, $plan] = planEntitlementFixture();

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['nonexistent_privilege' => 25]],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toContain('privilege_not_found');
});

it('returns a not found failure when the plan is missing', function (): void {
    [$organization] = planEntitlementFixture();

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: null,
        entitlementsParams: ['seats' => ['max' => 25]],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toContain('plan_not_found');
});

it('creates an entitlement without values when the payload has none', function (): void {
    [$organization, $plan] = planEntitlementFixture();

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => []],
        partial: false,
    );

    expect($result->success())->toBeTrue()
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(EntitlementValue::query()->count())->toBe(0);
});

it('stores booleans as t', function (): void {
    [$organization, $plan, $feature] = planEntitlementFixture();

    Privilege::factory()->forFeature($feature)->create(['code' => 'enabled', 'value_type' => 'boolean']);

    PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['enabled' => true]],
        partial: false,
    );

    expect(Entitlement::query()->where('plan_id', $plan->id)->sole()->values()->sole()->value)->toBe('t');
});

it('stores strings verbatim', function (): void {
    [$organization, $plan, $feature] = planEntitlementFixture();

    Privilege::factory()->forFeature($feature)->create(['code' => 'provider', 'value_type' => 'string']);

    PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['provider' => 'okta']],
        partial: false,
    );

    expect(Entitlement::query()->where('plan_id', $plan->id)->sole()->values()->sole()->value)->toBe('okta');
});

it('returns a validation failure on an invalid privilege value', function (): void {
    [$organization, $plan] = planEntitlementFixture();

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['max' => [12, 13]]],
        partial: false,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['max_privilege_value'])->toBe(['value_is_invalid']);
});

// -- Partial update (partial: true) -------------------------------------------------

it('updates the existing entitlement value in place', function (): void {
    [$organization, $plan, $feature, $privilege] = planEntitlementFixture();

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    $value = EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['max' => 60]],
        partial: true,
    );

    expect($result->success())->toBeTrue()
        ->and($value->refresh()->value)->toBe('60')
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(EntitlementValue::query()->count())->toBe(1)
        ->and($result->entitlements->pluck('id'))->toContain($entitlement->id);
});

it('creates the missing entitlement value on a partial update', function (): void {
    [$organization, $plan, $feature, $privilege] = planEntitlementFixture();

    $privilege2 = Privilege::factory()->forFeature($feature)->create(['code' => 'max_admins', 'value_type' => 'integer']);
    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: ['seats' => ['max_admins' => 30]],
        partial: true,
    );

    expect($result->success())->toBeTrue()
        ->and(EntitlementValue::query()->count())->toBe(2);

    $newValue = $entitlement->values()->where('entitlement_privilege_id', $privilege2->id)->sole();

    expect($newValue->value)->toBe('30');
});

it('creates the entitlement when the feature is not granted yet', function (): void {
    [$organization, $plan, $feature] = planEntitlementFixture();

    $newFeature = Feature::factory()->forOrganization($organization)->create();
    $newPrivilege = Privilege::factory()->forFeature($newFeature)->create(['code' => 'max_users', 'value_type' => 'integer']);

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: [$newFeature->code => ['max_users' => 50]],
        partial: true,
    );

    expect($result->success())->toBeTrue()
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(EntitlementValue::query()->count())->toBe(1);

    $entitlement = Entitlement::query()->sole();

    expect($entitlement->plan_id)->toBe($plan->id)
        ->and($entitlement->entitlement_feature_id)->toBe($newFeature->id)
        ->and($entitlement->organization_id)->toBe($organization->id)
        ->and($entitlement->values()->sole()->value)->toBe('50');
});

it('updates values across multiple features', function (): void {
    [$organization, $plan, $feature, $privilege] = planEntitlementFixture();

    $feature2 = Feature::factory()->forOrganization($organization)->create();
    $privilege3 = Privilege::factory()->forFeature($feature2)->create(['code' => 'max_storage', 'value_type' => 'integer']);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    $entitlement2 = Entitlement::factory()->forOrganization($organization)->forFeature($feature2)->forPlan($plan)->create();

    $value = EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);
    $value2 = EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement2, $privilege3)->create(['value' => '100']);

    $result = PlanEntitlementsUpdateService::call(
        organization: $organization,
        plan: $plan,
        entitlementsParams: [
            $feature->code => ['max' => 60],
            $feature2->code => ['max_storage' => 200],
        ],
        partial: true,
    );

    expect($result->success())->toBeTrue()
        ->and($value->refresh()->value)->toBe('60')
        ->and($value2->refresh()->value)->toBe('200')
        ->and($result->entitlements->pluck('id'))->toContain($entitlement->id, $entitlement2->id);
});
