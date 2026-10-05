<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Support\CurrentContext;
use App\Models\EntitlementValue;
use App\Services\Failures\NotFoundFailure;
use App\Services\Entitlements\PlanEntitlementDestroyService;
use App\Services\Entitlements\PlanEntitlementPrivilegeDestroyService;

beforeEach(function (): void {
    CurrentContext::reset();
});

/**
 * Ports of Rails' spec/services/entitlement/
 * plan_entitlement_destroy_service_spec.rb and
 * plan_entitlement_privilege_destroy_service_spec.rb.
 */
function planDestroyFixture(): array
{
    $organization = CurrentContext::$organization = Organization::factory()->create();
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $feature = Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);
    $privilege = Privilege::factory()->forFeature($feature)->create(['code' => 'max', 'value_type' => 'integer']);
    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();

    return [$organization, $plan, $feature, $privilege, $entitlement];
}

it('discards the entitlement and its values', function (): void {
    [$organization, $plan, $feature, $privilege, $entitlement] = planDestroyFixture();

    $value = EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);

    $result = PlanEntitlementDestroyService::call(entitlement: $entitlement);

    expect($result->success())->toBeTrue()
        ->and($result->entitlement->code ?? $result->entitlement)->not->toBeNull()
        ->and($entitlement->refresh()->deleted_at)->not->toBeNull()
        ->and($value->refresh()->deleted_at)->not->toBeNull();
})->group('ledger:svc:Entitlement.PlanEntitlementDestroyService');

it('returns a not found failure when the entitlement is missing', function (): void {
    $result = PlanEntitlementDestroyService::call(entitlement: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->resource)->toBe('entitlement');
});

it('discards a single privilege value and reloads the entitlement', function (): void {
    [$organization, $plan, $feature, $privilege, $entitlement] = planDestroyFixture();

    $value = EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);

    $result = PlanEntitlementPrivilegeDestroyService::call(
        entitlement: $entitlement,
        privilegeCode: 'max',
    );

    expect($result->success())->toBeTrue()
        ->and($result->entitlement)->toBeInstanceOf(Entitlement::class)
        ->and($value->refresh()->deleted_at)->not->toBeNull()
        ->and($entitlement->refresh()->deleted_at)->toBeNull();
});

it('returns a not found failure for an unknown privilege code', function (): void {
    [$organization, $plan, $feature, $privilege, $entitlement] = planDestroyFixture();

    $result = PlanEntitlementPrivilegeDestroyService::call(
        entitlement: $entitlement,
        privilegeCode: 'nonexistent',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->resource)->toBe('privilege');
});

it('returns a not found failure when the entitlement is missing for the privilege remove', function (): void {
    $result = PlanEntitlementPrivilegeDestroyService::call(
        entitlement: null,
        privilegeCode: 'max',
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->resource)->toBe('entitlement');
});
