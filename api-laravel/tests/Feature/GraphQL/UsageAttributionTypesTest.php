<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Organization;
use App\Models\UsageAttributionType;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of Rails' spec/graphql/resolvers/{usage_attribution_type_resolver,
 * usage_attribution_types_resolver}_spec.rb and the mutations specs over
 * the frozen SDL. Ledger rows: gql:query:usageAttributionType,
 * gql:query:usageAttributionTypes, gql:mutation:createUsageAttributionType /
 * updateUsageAttributionType / destroyUsageAttributionType.
 */
beforeEach(function (): void {
    Queue::fake();
});

function usageAttributionGqlOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create(array_merge([
        'feature_flags' => ['account_tree'],
    ], $attributes));

    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const USAGE_ATTRIBUTION_TYPE_QUERY = <<<'GQL'
query($id: ID!) {
    usageAttributionType(id: $id) {
        id code name role attributionKeys
        parent { id code }
        children { id code }
    }
}
GQL;

const USAGE_ATTRIBUTION_TYPES_LIST = <<<'GQL'
query($searchTerm: String, $role: UsageAttributionTypeRoleEnum, $roots: Boolean) {
    usageAttributionTypes(limit: 10, searchTerm: $searchTerm, role: $role, roots: $roots) {
        collection { id code name role attributionKeys parent { id code } children { id code } }
        metadata { currentPage totalCount }
    }
}
GQL;

const CREATE_USAGE_ATTRIBUTION_TYPE_MUTATION = <<<'GQL'
mutation($input: CreateUsageAttributionTypeInput!) {
    createUsageAttributionType(input: $input) {
        id code name description role attributionKeys parent { id code }
    }
}
GQL;

const UPDATE_USAGE_ATTRIBUTION_TYPE_MUTATION = <<<'GQL'
mutation($input: UpdateUsageAttributionTypeInput!) {
    updateUsageAttributionType(input: $input) { id code name role }
}
GQL;

const DESTROY_USAGE_ATTRIBUTION_TYPE_MUTATION = <<<'GQL'
mutation($input: DestroyUsageAttributionTypeInput!) {
    destroyUsageAttributionType(input: $input) { id }
}
GQL;

// -- feature flag gate ----------------------------------------------------------

it('rejects the usage attribution queries without the account_tree flag', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization(['feature_flags' => []]);

    $response = gqlPost(USAGE_ATTRIBUTION_TYPES_LIST, [], gqlAuthHeaders($user, $organization->id));

    $error = $response->json('errors.0.extensions');

    expect($error['status'])->toBe(403)
        ->and($error['code'])->toBe('feature_unavailable');
});

it('rejects the usage attribution mutations without the account_tree flag', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization(['feature_flags' => []]);

    $response = gqlPost(CREATE_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => [
        'code' => 'team', 'role' => 'hierarchical', 'attributionKeys' => ['team'],
    ]], gqlAuthHeaders($user, $organization->id));

    $error = $response->json('errors.0.extensions');

    expect($error['status'])->toBe(403)
        ->and($error['code'])->toBe('feature_unavailable');
});

// -- queries ----------------------------------------------------------------

it('returns a usage attribution type by id', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $parent = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'division', 'name' => 'Division',
    ]);
    $child = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'team', 'name' => 'Team',
        'parent_id' => $parent->id, 'attribution_keys' => ['team', 'member'],
    ]);

    $response = gqlPost(USAGE_ATTRIBUTION_TYPE_QUERY, ['id' => $child->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.usageAttributionType');

    expect($payload['id'])->toBe($child->id)
        ->and($payload['code'])->toBe('team')
        ->and($payload['role'])->toBe('hierarchical')
        ->and($payload['attributionKeys'])->toBe(['team', 'member'])
        ->and($payload['parent']['code'])->toBe('division');
});

it('answers not_found for an unknown usage attribution type', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $response = gqlPost(USAGE_ATTRIBUTION_TYPE_QUERY, ['id' => '00000000-0000-0000-0000-000000000000'], gqlAuthHeaders($user, $organization->id));

    $error = $response->json('errors.0.extensions');

    expect($error['status'])->toBe(404)
        ->and($error['code'])->toBe('not_found');
});

it('lists usage attribution types with the search term', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $matched = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'engineering', 'name' => 'Engineering',
    ]);
    UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'sales', 'name' => 'Sales',
    ]);

    $response = gqlPost(USAGE_ATTRIBUTION_TYPES_LIST, ['searchTerm' => 'engineer'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.usageAttributionTypes');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($matched->id)
        ->and($payload['metadata']['totalCount'])->toBe(1);
});

it('filters usage attribution types by role and roots', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $root = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'division',
    ]);
    UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'team', 'parent_id' => $root->id,
    ]);
    UsageAttributionType::factory()->flat()->create([
        'organization_id' => $organization->id, 'code' => 'flat_type',
    ]);

    // roots: only the types without a kept parent.
    $payload = gqlPost(USAGE_ATTRIBUTION_TYPES_LIST, ['roots' => true], gqlAuthHeaders($user, $organization->id))
        ->json('data.usageAttributionTypes');

    expect(collect($payload['collection'])->pluck('code')->sort()->values()->all())->toBe(['division', 'flat_type']);

    // role: only the flat one.
    $payload = gqlPost(USAGE_ATTRIBUTION_TYPES_LIST, ['role' => 'flat'], gqlAuthHeaders($user, $organization->id))
        ->json('data.usageAttributionTypes');

    expect(collect($payload['collection'])->pluck('code')->all())->toBe(['flat_type'])
        ->and($payload['collection'][0]['role'])->toBe('flat');
});

it('does not leak another organization usage attribution types', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    UsageAttributionType::factory()->create(['organization_id' => $organization->id, 'code' => 'ours']);
    UsageAttributionType::factory()->create(['code' => 'theirs']);

    $payload = gqlPost(USAGE_ATTRIBUTION_TYPES_LIST, [], gqlAuthHeaders($user, $organization->id))
        ->json('data.usageAttributionTypes');

    expect(collect($payload['collection'])->pluck('code')->all())->toBe(['ours']);
});

// -- mutations ----------------------------------------------------------------

it('creates a usage attribution type with a parent', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $parent = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'division', 'attribution_keys' => ['division'],
    ]);

    $response = gqlPost(CREATE_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => [
        'code' => ' team ',
        'name' => 'Team',
        'description' => 'Engineering teams',
        'role' => 'hierarchical',
        'attributionKeys' => ['team', 'team ', 'member'],
        'parentId' => $parent->id,
    ]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.createUsageAttributionType');

    expect($payload['code'])->toBe('team')
        ->and($payload['attributionKeys'])->toBe(['team', 'member'])
        ->and($payload['role'])->toBe('hierarchical')
        ->and($payload['parent']['id'])->toBe($parent->id);
})->group('ledger:svc:UsageAttributionTypes.CreateService');

it('fails to create a usage attribution type with a taken code', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    UsageAttributionType::factory()->create(['organization_id' => $organization->id, 'code' => 'team']);

    $response = gqlPost(CREATE_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => [
        'code' => 'team', 'role' => 'hierarchical', 'attributionKeys' => ['team'],
    ]], gqlAuthHeaders($user, $organization->id));

    $error = $response->json('errors.0.extensions');

    expect($error['status'])->toBe(422)
        ->and($response->json('errors.0.extensions.details.code'))->toBe(['has already been taken']);
})->group('ledger:svc:UsageAttributionTypes.CreateService');

it('updates a usage attribution type', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $type = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'team', 'name' => 'Team',
    ]);

    $response = gqlPost(UPDATE_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => [
        'id' => $type->id, 'name' => 'Renamed', 'role' => 'flat',
    ]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.updateUsageAttributionType');

    expect($payload['id'])->toBe($type->id)
        ->and($payload['name'])->toBe('Renamed')
        ->and($payload['role'])->toBe('flat');
})->group('ledger:svc:UsageAttributionTypes.UpdateService');

it('refuses to flatten a type with children', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $type = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'division',
    ]);
    UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'team', 'parent_id' => $type->id,
    ]);

    $response = gqlPost(UPDATE_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => [
        'id' => $type->id, 'role' => 'flat',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.status'))->toBe(422)
        ->and($response->json('errors.0.extensions.details.role'))->toBe(['cannot_be_flat_with_children']);
})->group('ledger:svc:UsageAttributionTypes.UpdateService');

it('freezes code and parent once values are attributed', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $type = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'team',
    ]);

    App\Models\UsageAttributionValue::factory()->create([
        'organization_id' => $organization->id,
        'usage_attribution_type_id' => $type->id,
    ]);

    $response = gqlPost(UPDATE_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => [
        'id' => $type->id, 'code' => 'renamed',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.details.code'))->toBe(['usage_already_attributed']);

    // name stays editable.
    $response = gqlPost(UPDATE_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => [
        'id' => $type->id, 'name' => 'Still editable',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.updateUsageAttributionType.name'))->toBe('Still editable');
})->group('ledger:svc:UsageAttributionTypes.UpdateService');

it('destroys a usage attribution type and its attributed values', function (): void {
    [$organization, $user] = usageAttributionGqlOrganization();

    $type = UsageAttributionType::factory()->create([
        'organization_id' => $organization->id, 'code' => 'team',
    ]);

    $value = App\Models\UsageAttributionValue::factory()->create([
        'organization_id' => $organization->id,
        'usage_attribution_type_id' => $type->id,
    ]);

    $response = gqlPost(DESTROY_USAGE_ATTRIBUTION_TYPE_MUTATION, ['input' => ['id' => $type->id]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('data.destroyUsageAttributionType.id'))->toBe($type->id)
        ->and($type->refresh()->trashed())->toBeTrue()
        ->and($value->refresh()->trashed())->toBeTrue();
})->group('ledger:svc:UsageAttributionTypes.DestroyService');
