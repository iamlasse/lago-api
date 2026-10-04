<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/subscriptions/:external_id/entitlements',
    'ledger:rest:PATCH:/api/v1/subscriptions/:external_id/entitlements',
    'ledger:rest:DELETE:/api/v1/subscriptions/:external_id/entitlements/:code',
    'ledger:rest:DELETE:/api/v1/subscriptions/:external_id/entitlements/:code/privileges/:code',
);

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\EntitlementValue;
use App\Models\SubscriptionFeatureRemoval;

/**
 * Port of Rails' spec/requests/api/v1/subscriptions/entitlements_controller_spec.rb
 * (and the nested privileges_controller_spec.rb).
 */
function subEntitlementsOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

function subEntitlementsFixture(Organization $organization): array
{
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub_external_id',
    ]);
    $feature = Feature::factory()->forOrganization($organization)->create([
        'code' => 'seats',
        'name' => 'Feature Name',
        'description' => 'Feature Description',
    ]);
    $privilege1 = Privilege::factory()->forFeature($feature)->create(['code' => 'max', 'name' => null, 'value_type' => 'integer']);
    $privilege2 = Privilege::factory()->forFeature($feature)->create(['code' => 'root?', 'name' => null, 'value_type' => 'boolean']);

    return [$plan, $subscription, $feature, $privilege1, $privilege2];
}

function subEntitlementsAuth(Organization $organization, $apiKey): array
{
    return ['Authorization' => 'Bearer '.$apiKey->value];
}

function planGrant(Organization $organization, Plan $plan, Feature $feature, Privilege $privilege, string $value): Entitlement
{
    $entitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();

    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => $value]);

    return $entitlement;
}

function subscriptionOverride(Organization $organization, Subscription $subscription, Feature $feature, Privilege $privilege, string $value): Entitlement
{
    // One entitlement per (subscription, feature) — the unique index
    // idx_unique_feature_per_subscription enforces it.
    $entitlement = Entitlement::query()
        ->where('subscription_id', $subscription->id)
        ->where('entitlement_feature_id', $feature->id)
        ->firstOr(fn () => Entitlement::factory()
            ->forOrganization($organization)
            ->forFeature($feature)
            ->forSubscription($subscription)
            ->create());

    EntitlementValue::factory()->forEntitlementAndPrivilege($entitlement, $privilege)->create(['value' => $value]);

    return $entitlement;
}

// -- GET /api/v1/subscriptions/:external_id/entitlements ---------------------------

it('returns the merged entitlement view', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1, $privilege2] = subEntitlementsFixture($organization);

    planGrant($organization, $plan, $feature, $privilege1, '30');
    subscriptionOverride($organization, $subscription, $feature, $privilege2, 't');

    $payload = $this->getJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements',
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    expect(count($payload))->toBe(1);

    $seats = $payload[0];

    expect($seats['code'])->toBe('seats')
        ->and($seats['name'])->toBe('Feature Name')
        ->and($seats['description'])->toBe('Feature Description')
        ->and($seats['overrides'])->toBe(['root?' => true]);

    $privileges = collect($seats['privileges'])->keyBy('code');

    // NOTE: the config key is emitted last (Rails' hash order).
    expect($privileges['max'])->toBe([
        'code' => 'max',
        'name' => null,
        'value_type' => 'integer',
        'value' => 30,
        'plan_value' => 30,
        'override_value' => null,
        'config' => [],
    ])->and($privileges['root?'])->toBe([
        'code' => 'root?',
        'name' => null,
        'value_type' => 'boolean',
        'value' => true,
        'plan_value' => null,
        'override_value' => true,
        'config' => [],
    ]);
});

it('answers subscription_not_found on an unknown external id', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();

    $this->getJson('/api/v1/subscriptions/invalid_subscription/entitlements', subEntitlementsAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'subscription_not_found')
            ->etc());
});

it('resolves the active subscription by default', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1] = subEntitlementsFixture($organization);

    $pending = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub_external_id',
        'status' => 0, // pending
    ]);
    $terminated = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub_external_id',
        'status' => 2, // terminated
    ]);

    planGrant($organization, $plan, $feature, $privilege1, '0');
    subscriptionOverride($organization, $subscription, $feature, $privilege1, '100');
    subscriptionOverride($organization, $pending, $feature, $privilege1, '200');
    subscriptionOverride($organization, $terminated, $feature, $privilege1, '300');

    $payload = $this->getJson(
        '/api/v1/subscriptions/sub_external_id/entitlements',
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    expect($payload[0]['overrides'])->toBe(['max' => 100]);
});

it('resolves the pending subscription via the subscription_status param', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1] = subEntitlementsFixture($organization);

    $pending = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub_external_id',
        'status' => 0, // pending
    ]);

    planGrant($organization, $plan, $feature, $privilege1, '0');
    subscriptionOverride($organization, $subscription, $feature, $privilege1, '100');
    subscriptionOverride($organization, $pending, $feature, $privilege1, '200');

    $payload = $this->getJson(
        '/api/v1/subscriptions/sub_external_id/entitlements?subscription_status=pending',
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    expect($payload[0]['overrides'])->toBe(['max' => 200]);
});

it('accepts the legacy status param', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1] = subEntitlementsFixture($organization);

    $terminated = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'external_id' => 'sub_external_id',
        'status' => 2, // terminated
    ]);

    planGrant($organization, $plan, $feature, $privilege1, '0');
    subscriptionOverride($organization, $subscription, $feature, $privilege1, '100');
    subscriptionOverride($organization, $terminated, $feature, $privilege1, '300');

    $payload = $this->getJson(
        '/api/v1/subscriptions/sub_external_id/entitlements?status=terminated',
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    expect($payload[0]['overrides'])->toBe(['max' => 300]);
});

it('honors the subscription feature removal tombstone', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1] = subEntitlementsFixture($organization);

    planGrant($organization, $plan, $feature, $privilege1, '30');

    SubscriptionFeatureRemoval::factory()->forSubscriptionAndFeature($subscription, $feature)->create();

    $payload = $this->getJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements',
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    expect($payload)->toBe([]);
});

// -- PATCH /api/v1/subscriptions/:external_id/entitlements ---------------------------

it('merges entitlement overrides on PATCH', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1] = subEntitlementsFixture($organization);

    planGrant($organization, $plan, $feature, $privilege1, '30');

    $payload = $this->patchJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements',
        ['entitlements' => ['seats' => ['max' => 55]]],
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    $privileges = collect($payload[0]['privileges'])->keyBy('code');

    expect($privileges['max']['value'])->toBe(55)
        ->and($privileges['max']['plan_value'])->toBe(30)
        ->and($privileges['max']['override_value'])->toBe(55)
        ->and($payload[0]['overrides'])->toBe(['max' => 55]);
});

it('answers feature_not_found on PATCH', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription] = subEntitlementsFixture($organization);

    $this->patchJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements',
        ['entitlements' => ['nonexistent' => ['max' => 1]]],
        subEntitlementsAuth($organization, $apiKey),
    )->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'feature_not_found')
            ->etc());
});

it('answers subscription_not_found on PATCH', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();

    $this->patchJson('/api/v1/subscriptions/invalid_subscription/entitlements', [
        'entitlements' => ['seats' => ['max' => 1]],
    ], subEntitlementsAuth($organization, $apiKey))
        ->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'subscription_not_found')
            ->etc());
});

// -- DELETE /api/v1/subscriptions/:external_id/entitlements/:feature_code -------------

it('removes a feature entitlement', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1] = subEntitlementsFixture($organization);

    planGrant($organization, $plan, $feature, $privilege1, '30');
    subscriptionOverride($organization, $subscription, $feature, $privilege1, '50');

    $payload = $this->deleteJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements/seats',
        [],
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    // The override is discarded AND a removal tombstone is recorded (the
    // feature is on the plan) — the merged view hides the feature entirely.
    expect($payload)->toBe([])
        ->and(SubscriptionFeatureRemoval::query()->count())->toBe(1);
});

it('removes an inherited feature and answers the empty list', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1] = subEntitlementsFixture($organization);

    planGrant($organization, $plan, $feature, $privilege1, '30');

    $payload = $this->deleteJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements/seats',
        [],
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    expect($payload)->toBe([])
        ->and(SubscriptionFeatureRemoval::query()->count())->toBe(1);
});

it('answers subscription_not_found on feature removal', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();

    $this->deleteJson(
        '/api/v1/subscriptions/invalid_subscription/entitlements/seats',
        [],
        subEntitlementsAuth($organization, $apiKey),
    )->assertNotFound();
});

it('answers feature_not_found on feature removal', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription] = subEntitlementsFixture($organization);

    $this->deleteJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements/nonexistent',
        [],
        subEntitlementsAuth($organization, $apiKey),
    )->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'feature_not_found')
            ->etc());
});

// -- DELETE .../entitlements/:entitlement_code/privileges/:code ------------------------

it('removes a single privilege override', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription, $feature, $privilege1, $privilege2] = subEntitlementsFixture($organization);

    planGrant($organization, $plan, $feature, $privilege1, '30');
    subscriptionOverride($organization, $subscription, $feature, $privilege1, '50');
    subscriptionOverride($organization, $subscription, $feature, $privilege2, 't');

    $payload = $this->deleteJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements/seats/privileges/max',
        [],
        subEntitlementsAuth($organization, $apiKey),
    )->assertOk()->json('entitlements');

    // The privilege tombstone hides only the 'max' privilege; the feature
    // stays with the root? override.
    expect(count($payload))->toBe(1)
        ->and($payload[0]['overrides'])->toBe(['root?' => true])
        ->and(collect($payload[0]['privileges'])->pluck('code'))->not->toContain('max')
        ->and(SubscriptionFeatureRemoval::query()->where('entitlement_privilege_id', $privilege1->id)->count())->toBe(1);
});

it('answers privilege_not_found on the privilege removal', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();
    [$plan, $subscription] = subEntitlementsFixture($organization);

    $this->deleteJson(
        '/api/v1/subscriptions/'.$subscription->external_id.'/entitlements/seats/privileges/nonexistent',
        [],
        subEntitlementsAuth($organization, $apiKey),
    )->assertNotFound()
        ->assertJson(fn (Illuminate\Testing\Fluent\AssertableJson $json) => $json
            ->where('code', 'privilege_not_found')
            ->etc());
});

it('answers subscription_not_found on the privilege removal', function (): void {
    [$organization, $apiKey] = subEntitlementsOrganization();

    $this->deleteJson(
        '/api/v1/subscriptions/invalid_subscription/entitlements/seats/privileges/max',
        [],
        subEntitlementsAuth($organization, $apiKey),
    )->assertNotFound();
});
