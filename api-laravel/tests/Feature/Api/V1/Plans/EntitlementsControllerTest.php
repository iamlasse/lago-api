<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/plans/:code/entitlements',
    'ledger:rest:GET:/api/v1/plans/:code/entitlements/:code',
    'ledger:rest:POST:/api/v1/plans/:code/entitlements',
    'ledger:rest:PATCH:/api/v1/plans/:code/entitlements',
    'ledger:rest:DELETE:/api/v1/plans/:code/entitlements/:code',
    'ledger:rest:DELETE:/api/v1/plans/:code/entitlements/:code/privileges/:code',
);

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\EntitlementValue;

/**
 * Port of Rails' spec/requests/api/v1/plans/entitlements_controller_spec.rb
 * (and the nested privileges_controller_spec.rb).
 */
function planEntitlementsOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

function planEntitlementsFixture(Organization $organization): array
{
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'plan_code']);
    $feature = Feature::factory()->forOrganization($organization)->create(['code' => 'seats']);
    $privilege = Privilege::factory()->forFeature($feature)->create(['code' => 'max', 'value_type' => 'integer']);

    return [$plan, $feature, $privilege];
}

function planEntitlementsAuth(Organization $organization, $apiKey): array
{
    return ['Authorization' => 'Bearer '.$apiKey->value];
}

// -- GET /api/v1/plans/:plan_code/entitlements -----------------------------------

it('returns the list of plan entitlements', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '30']);

    $payload = $this->getJson('/api/v1/plans/'.$plan->code.'/entitlements', planEntitlementsAuth($organization, $apiKey))
        ->assertOk()
        ->json('entitlements');

    expect(count($payload))->toBe(1)
        ->and($payload[0]['code'])->toBe('seats')
        ->and($payload[0]['privileges'][0]['value'])->toBe(30);
});

it('excludes orphaned entitlements whose feature was discarded', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '30']);

    $feature->delete();

    $this->getJson('/api/v1/plans/'.$plan->code.'/entitlements', planEntitlementsAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('entitlements', [])
            ->etc());
});

it('answers plan_not_found for an unknown plan', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();

    $this->getJson('/api/v1/plans/invalid_plan/entitlements', planEntitlementsAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'plan_not_found')
            ->etc());
});

it('does not resolve child plans', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    // A child plan override on the same code; the parent is discarded.
    $child = Plan::factory()->create([
        'organization_id' => $organization->id,
        'code' => $plan->code,
        'parent_id' => $plan->id,
    ]);

    $childEntitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($child)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($childEntitlement, $privilege)->create(['value' => '999']);

    $plan->delete();

    $this->getJson('/api/v1/plans/'.$plan->code.'/entitlements', planEntitlementsAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'plan_not_found')
            ->etc());
});

// -- GET /api/v1/plans/:plan_code/entitlements/:feature_code -----------------------

it('shows a plan entitlement', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '30']);

    $this->getJson(
        '/api/v1/plans/'.$plan->code.'/entitlements/'.$feature->code,
        planEntitlementsAuth($organization, $apiKey),
    )->assertOk()->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
        ->where('entitlement.code', 'seats')
        ->where('entitlement.privileges.0.value', 30)
        ->etc());
});

it('answers not found for an unknown entitlement code', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan] = planEntitlementsFixture($organization);

    $this->getJson(
        '/api/v1/plans/'.$plan->code.'/entitlements/invalid_feature',
        planEntitlementsAuth($organization, $apiKey),
    )->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'entitlement_not_found')
            ->etc());
});

// -- POST /api/v1/plans/:plan_code/entitlements (full sync) -------------------------

it('creates plan entitlements', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    $this->postJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => ['seats' => ['max' => 25]],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->count('entitlements', 1)
            ->where('entitlements.0.privileges.0.value', 25)
            ->etc());

    expect(Entitlement::query()->count())->toBe(1)
        ->and(EntitlementValue::query()->count())->toBe(1);
});

it('replaces existing entitlements on create', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    // Rails: the pre-existing entitlement belongs to a different feature.
    $otherFeature = Feature::factory()->forOrganization($organization)->create();
    $existing = Entitlement::factory()->forOrganization($organization)->forFeature($otherFeature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($existing, $privilege)->create(['value' => '10']);

    $this->postJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => ['seats' => ['max' => 25]],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('entitlements.0.privileges.0.value', 25)
            ->etc());

    expect($existing->refresh()->deleted_at)->not->toBeNull()
        ->and(Entitlement::query()->count())->toBe(1);
});

it('answers feature_not_found on create', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan] = planEntitlementsFixture($organization);

    $this->postJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => ['nonexistent_feature' => ['max' => 25]],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'feature_not_found')
            ->etc());
});

it('answers privilege_not_found on create', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan] = planEntitlementsFixture($organization);

    $this->postJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => ['seats' => ['nonexistent_privilege' => 25]],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'privilege_not_found')
            ->etc());
});

it('validates the privilege value on create', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan] = planEntitlementsFixture($organization);

    $this->postJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => ['seats' => ['max' => [12, 13]]],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'validation_errors')
            ->where('error_details.max_privilege_value', ['value_is_invalid'])
            ->etc());
});

it('validates select_options membership on create', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature] = planEntitlementsFixture($organization);

    Privilege::factory()->forFeature($feature)->select(['email', 'phone', 'slack'])->create([
        'code' => 'invitation',
        'value_type' => 'select',
    ]);

    $this->postJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => ['seats' => ['invitation' => 'okta']],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertUnprocessable()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'validation_errors')
            ->where('error_details.invitation_privilege_value', ['value_not_in_select_options'])
            ->etc());
});

it('answers plan_not_found on create', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();

    $this->postJson('/api/v1/plans/invalid_plan/entitlements', [
        'entitlements' => ['seats' => ['max' => 25]],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'plan_not_found')
            ->etc());
});

it('answers an empty list when the entitlements params are empty', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan] = planEntitlementsFixture($organization);

    $this->postJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => [],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('entitlements', [])
            ->etc());
});

// -- PATCH /api/v1/plans/:plan_code/entitlements (partial merge) ---------------------

it('merges entitlements on PATCH', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);

    $this->patchJson('/api/v1/plans/'.$plan->code.'/entitlements', [
        'entitlements' => ['seats' => ['max' => 60]],
    ], planEntitlementsAuth($organization, $apiKey))
        ->assertOk()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('entitlements.0.privileges.0.value', 60)
            ->etc());

    expect(Entitlement::query()->count())->toBe(1)
        ->and(EntitlementValue::query()->count())->toBe(1);
});

// -- DELETE /api/v1/plans/:plan_code/entitlements/:feature_code -----------------------

it('destroys a plan entitlement', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);

    $this->deleteJson(
        '/api/v1/plans/'.$plan->code.'/entitlements/'.$feature->code,
        [],
        planEntitlementsAuth($organization, $apiKey),
    )->assertOk()->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
        ->where('entitlement.code', 'seats')
        ->etc());

    expect($entitlement->refresh()->deleted_at)->not->toBeNull()
        ->and(EntitlementValue::query()->count())->toBe(0);
});

it('answers not found when destroying an unknown entitlement', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan] = planEntitlementsFixture($organization);

    $this->deleteJson(
        '/api/v1/plans/'.$plan->code.'/entitlements/invalid_feature',
        [],
        planEntitlementsAuth($organization, $apiKey),
    )->assertNotFound();
});

// -- DELETE .../entitlements/:entitlement_code/privileges/:code ------------------------

it('destroys a single privilege value', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature, $privilege] = planEntitlementsFixture($organization);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    $other = Privilege::factory()->forFeature($feature)->create(['code' => 'max_admins', 'value_type' => 'integer']);

    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => '10']);
    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $other)->create(['value' => '3']);

    $this->deleteJson(
        '/api/v1/plans/'.$plan->code.'/entitlements/'.$feature->code.'/privileges/max',
        [],
        planEntitlementsAuth($organization, $apiKey),
    )->assertOk()->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
        ->where('entitlement.code', 'seats')
        ->count('entitlement.privileges', 1)
        ->etc());

    expect(EntitlementValue::query()->count())->toBe(1)
        ->and($entitlement->refresh()->deleted_at)->toBeNull();
});

it('answers privilege_not_found on the nested privilege destroy', function (): void {
    [$organization, $apiKey] = planEntitlementsOrganization();
    [$plan, $feature] = planEntitlementsFixture($organization);

    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();

    $this->deleteJson(
        '/api/v1/plans/'.$plan->code.'/entitlements/'.$feature->code.'/privileges/nonexistent',
        [],
        planEntitlementsAuth($organization, $apiKey),
    )->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'privilege_not_found')
            ->etc());
});
