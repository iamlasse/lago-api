<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Plan;
use App\Models\Feature;
use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\EntitlementValue;
use App\Models\SubscriptionFeatureRemoval;
use App\Models\Entitlement\SubscriptionEntitlement;

/**
 * Ports of Rails' spec/graphql/mutations/entitlement/*_spec.rb, the
 * resolvers' specs (features_resolver, feature_resolver,
 * subscription_entitlement_resolver[s]) and
 * spec/requests/api/v1/subscriptions/entitlements_controller_spec.rb over
 * the frozen SDL.
 *
 * Ledger rows: gql:query:features, gql:query:feature,
 * gql:query:subscriptionEntitlements, gql:query:subscriptionEntitlement,
 * gql:mutation:createFeature, gql:mutation:updateFeature,
 * gql:mutation:destroyFeature,
 * gql:mutation:createOrUpdateSubscriptionEntitlement,
 * gql:mutation:removeSubscriptionEntitlement.
 */
function gqlFeaturesSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlFeaturesFeature(Organization $organization): array
{
    $feature = Feature::factory()->forOrganization($organization)->create([
        'code' => 'seats',
        'name' => 'Feature Name',
        'description' => 'Feature Description',
    ]);

    Privilege::factory()->forFeature($feature)->create([
        'code' => 'max',
        'name' => 'Max',
        'value_type' => 'integer',
    ]);

    return [$feature, $feature->privileges()->sole()];
}

const FEATURES_QUERY = <<<'GQL'
query($page: Int, $limit: Int, $searchTerm: String) {
    features(page: $page, limit: $limit, searchTerm: $searchTerm) {
        collection {
            id
            code
            name
            description
            privileges { code name valueType config { selectOptions } }
            subscriptionsCount
            createdAt
        }
        metadata { currentPage limitValue totalPages totalCount }
    }
}
GQL;

const FEATURE_QUERY = <<<'GQL'
query($id: ID, $code: String) {
    feature(id: $id, code: $code) {
        id
        code
        name
        privileges { code valueType }
    }
}
GQL;

const CREATE_FEATURE_MUTATION = <<<'GQL'
mutation($code: String!, $name: String, $description: String, $privileges: [UpdatePrivilegeInput!]!) {
    createFeature(input: { code: $code, name: $name, description: $description, privileges: $privileges }) {
        id
        code
        name
        description
        privileges { code name valueType config { selectOptions } }
    }
}
GQL;

const UPDATE_FEATURE_MUTATION = <<<'GQL'
mutation($id: ID!, $privileges: [UpdatePrivilegeInput!]!) {
    updateFeature(input: { id: $id, privileges: $privileges }) {
        id
        code
        privileges { code valueType }
    }
}
GQL;

const DESTROY_FEATURE_MUTATION = <<<'GQL'
mutation($id: ID!) {
    destroyFeature(input: { id: $id }) {
        id
        code
    }
}
GQL;

const SUBSCRIPTION_ENTITLEMENTS_QUERY = <<<'GQL'
query($subscriptionId: ID!) {
    subscriptionEntitlements(subscriptionId: $subscriptionId) {
        collection {
            code
            name
            description
            privileges { code valueType value config { selectOptions } }
        }
        metadata { totalCount }
    }
}
GQL;

const SUBSCRIPTION_ENTITLEMENT_MUTATION = <<<'GQL'
mutation($subscriptionId: ID!, $featureCode: String!, $privileges: [EntitlementPrivilegeInput!]) {
    createOrUpdateSubscriptionEntitlement(input: {
        subscriptionId: $subscriptionId
        entitlement: { featureCode: $featureCode, privileges: $privileges }
    }) {
        code
        privileges { code value }
    }
}
GQL;

const REMOVE_SUBSCRIPTION_ENTITLEMENT_MUTATION = <<<'GQL'
mutation($subscriptionId: ID!, $featureCode: String!) {
    removeSubscriptionEntitlement(input: { subscriptionId: $subscriptionId, featureCode: $featureCode }) {
        featureCode
    }
}
GQL;

// -- query features ---------------------------------------------------------------

it('queries the organization features with their subscriptions counts', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature] = gqlFeaturesFeature($organization);

    $other = gqlCreateOrganization('Other Corp');
    gqlFeaturesFeature($other);

    $response = gqlPost(FEATURES_QUERY, [], gqlAuthHeaders($user, $organization->id));
    $response->assertOk();

    $payload = $response->json('data.features');

    expect(count($payload['collection']))->toBe(1)
        ->and($payload['collection'][0]['code'])->toBe('seats')
        ->and($payload['collection'][0]['name'])->toBe('Feature Name')
        ->and($payload['collection'][0]['privileges'][0]['code'])->toBe('max')
        ->and($payload['collection'][0]['privileges'][0]['valueType'])->toBe('integer')
        ->and($payload['collection'][0]['subscriptionsCount'])->toBe(0)
        ->and($payload['metadata']['totalCount'])->toBe(1);
})->group('gql:query:features');

it('paginates and searches the features', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    gqlFeaturesFeature($organization);
    Feature::factory()->forOrganization($organization)->create(['code' => 'storage']);

    $page = gqlPost(FEATURES_QUERY, ['page' => 1, 'limit' => 1], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.features');

    expect(count($page['collection']))->toBe(1)
        ->and($page['metadata']['totalCount'])->toBe(2)
        ->and($page['metadata']['totalPages'])->toBe(2);

    $searched = gqlPost(FEATURES_QUERY, ['searchTerm' => 'sto'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.features');

    expect(count($searched['collection']))->toBe(1)
        ->and($searched['collection'][0]['code'])->toBe('storage');
});

// -- query feature ------------------------------------------------------------------

it('queries a single feature by id or code', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature] = gqlFeaturesFeature($organization);

    $byId = gqlPost(FEATURE_QUERY, ['id' => $feature->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.feature');

    expect($byId['code'])->toBe('seats');

    $byCode = gqlPost(FEATURE_QUERY, ['code' => 'seats'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.feature');

    expect($byCode['id'])->toBe($feature->id);
})->group('gql:query:feature');

it('answers not found for an unknown feature', function (): void {
    [$organization, $user] = gqlFeaturesSetup();

    $response = gqlPost(FEATURE_QUERY, ['code' => 'nonexistent'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('errors.0.extensions'))->toBeArray();
});

// -- mutation createFeature -----------------------------------------------------------

it('creates a feature with privileges', function (): void {
    [$organization, $user] = gqlFeaturesSetup();

    $response = gqlPost(CREATE_FEATURE_MUTATION, [
        'code' => 'sso',
        'name' => 'SSO',
        'description' => 'SSO feature',
        'privileges' => [
            ['code' => 'provider', 'name' => 'Provider', 'valueType' => 'select', 'config' => ['selectOptions' => ['okta']]],
        ],
    ], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.createFeature');

    expect($payload['code'])->toBe('sso')
        ->and($payload['privileges'][0]['valueType'])->toBe('select')
        ->and($payload['privileges'][0]['config']['selectOptions'])->toBe(['okta']);

    expect(Feature::query()->where('organization_id', $organization->id)->count())->toBe(1);
})->group('gql:mutation:createFeature');

it('answers a validation error on a duplicated feature code', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    gqlFeaturesFeature($organization);

    $response = gqlPost(CREATE_FEATURE_MUTATION, [
        'code' => 'seats',
        'name' => null,
        'description' => null,
        'privileges' => [],
    ], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeArray()
        ->and($response->json('data.createFeature'))->toBeNull();
});

// -- mutation updateFeature ------------------------------------------------------------

it('updates a feature with full privilege semantics', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature] = gqlFeaturesFeature($organization);

    // Two privileges today; the input only carries "max" — the other one is
    // discarded (partial: false).
    $response = gqlPost(UPDATE_FEATURE_MUTATION, [
        'id' => $feature->id,
        'privileges' => [['code' => 'max', 'valueType' => 'integer']],
    ], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.updateFeature');

    expect($payload['code'])->toBe('seats')
        ->and(count($payload['privileges']))->toBe(1)
        ->and($payload['privileges'][0]['code'])->toBe('max')
        ->and(Privilege::query()->count())->toBe(1);
})->group('gql:mutation:updateFeature');

// -- mutation destroyFeature -------------------------------------------------------------

it('destroys a feature', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature] = gqlFeaturesFeature($organization);

    $response = gqlPost(DESTROY_FEATURE_MUTATION, ['id' => $feature->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('data.destroyFeature.code'))->toBe('seats')
        ->and(Feature::query()->count())->toBe(0)
        ->and(Feature::withTrashed()->count())->toBe(1);
})->group('gql:mutation:destroyFeature');

// -- subscription entitlements -------------------------------------------------------------

function gqlSubscriptionFixture(Organization $organization): array
{
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::factory()->create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
    ]);

    return [$plan, $subscription];
}

it('queries the subscription entitlements merged view', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature, $privilege] = gqlFeaturesFeature($organization);
    [$plan, $subscription] = gqlSubscriptionFixture($organization);

    $planEntitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($planEntitlement, $privilege)->create(['value' => '30']);

    $override = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($override, $privilege)->create(['value' => '42']);

    $payload = gqlPost(SUBSCRIPTION_ENTITLEMENTS_QUERY, ['subscriptionId' => $subscription->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.subscriptionEntitlements');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['code'])->toBe('seats');

    $privileges = collect($payload['collection'][0]['privileges'])->keyBy('code');

    // The GraphQL type carries the effective value only (the REST
    // serializer carries plan/override values).
    expect($privileges['max']['value'])->toBe('42');
})->group('gql:query:subscriptionEntitlements');

it('creates or updates the subscription entitlement', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature, $privilege] = gqlFeaturesFeature($organization);
    [$plan, $subscription] = gqlSubscriptionFixture($organization);

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create()->values()
        ->create(['organization_id' => $organization->id, 'entitlement_privilege_id' => $privilege->id, 'value' => '10']);

    $payload = gqlPost(SUBSCRIPTION_ENTITLEMENT_MUTATION, [
        'subscriptionId' => $subscription->id,
        'featureCode' => 'seats',
        'privileges' => [['privilegeCode' => 'max', 'value' => '55']],
    ], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createOrUpdateSubscriptionEntitlement');

    $privileges = collect($payload['privileges'])->keyBy('code');

    expect($payload['code'])->toBe('seats')
        ->and($privileges['max']['value'])->toBe('55');
})->group('gql:mutation:createOrUpdateSubscriptionEntitlement');

it('answers not found for an unknown subscription', function (): void {
    [$organization, $user] = gqlFeaturesSetup();

    $response = gqlPost(SUBSCRIPTION_ENTITLEMENT_MUTATION, [
        'subscriptionId' => '00000000-0000-0000-0000-000000000000',
        'featureCode' => 'seats',
        'privileges' => [],
    ], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeArray();
});

it('removes the subscription entitlement', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature, $privilege] = gqlFeaturesFeature($organization);
    [$plan, $subscription] = gqlSubscriptionFixture($organization);

    Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create()->values()
        ->create(['organization_id' => $organization->id, 'entitlement_privilege_id' => $privilege->id, 'value' => '10']);

    $payload = gqlPost(REMOVE_SUBSCRIPTION_ENTITLEMENT_MUTATION, [
        'subscriptionId' => $subscription->id,
        'featureCode' => 'seats',
    ], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.removeSubscriptionEntitlement');

    expect($payload['featureCode'])->toBe('seats')
        ->and(SubscriptionFeatureRemoval::query()->count())->toBe(1);
})->group('gql:mutation:removeSubscriptionEntitlement');

// -- query subscriptionEntitlement (single) ---------------------------------------------

const SUBSCRIPTION_ENTITLEMENT_QUERY = <<<'GQL'
query($subscriptionId: ID!, $featureCode: String!) {
    subscriptionEntitlement(subscriptionId: $subscriptionId, featureCode: $featureCode) {
        code
        name
        description
        privileges { code value }
    }
}
GQL;

it('fetches a single subscription entitlement merged view', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$feature, $privilege] = gqlFeaturesFeature($organization);
    [$plan, $subscription] = gqlSubscriptionFixture($organization);

    $planEntitlement = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forPlan($plan)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($planEntitlement, $privilege)->create(['value' => '30']);

    // The subscription override wins in the merged view.
    $override = Entitlement::factory()->forOrganization($organization)->forFeature($feature)->forSubscription($subscription)->create();
    EntitlementValue::factory()->forEntitlementAndPrivilege($override, $privilege)->create(['value' => '42']);

    $payload = gqlPost(SUBSCRIPTION_ENTITLEMENT_QUERY, [
        'subscriptionId' => $subscription->id,
        'featureCode' => 'seats',
    ], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.subscriptionEntitlement');

    expect($payload['code'])->toBe('seats')
        ->and($payload['name'])->toBe('Feature Name')
        ->and($payload['privileges'][0]['code'])->toBe('max')
        ->and($payload['privileges'][0]['value'])->toBe('42');
})->group('gql:query:subscriptionEntitlement');

it('answers not_found for an unknown entitlement on subscriptionEntitlement', function (): void {
    [$organization, $user] = gqlFeaturesSetup();
    [$plan, $subscription] = gqlSubscriptionFixture($organization);

    gqlPost(SUBSCRIPTION_ENTITLEMENT_QUERY, [
        'subscriptionId' => $subscription->id,
        'featureCode' => 'nonexistent',
    ], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    // An unknown subscription answers the same envelope.
    gqlPost(SUBSCRIPTION_ENTITLEMENT_QUERY, [
        'subscriptionId' => '00000000-0000-0000-0000-000000000000',
        'featureCode' => 'seats',
    ], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');
})->group('gql:query:subscriptionEntitlement');
