<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\BillingEntity;

/**
 * Ports of the billing entities GraphQL surface over the frozen SDL
 * (Rails: mutations/billing_entities/{create,update,destroy}, resolvers
 * {billing_entity,billing_entities}).
 *
 * Ledger rows: gql:mutation:createBillingEntity, gql:mutation:updateBillingEntity,
 * gql:mutation:destroyBillingEntity, gql:query:billingEntity,
 * gql:query:billingEntities.
 */
function gqlBillingEntitiesSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('billing-entities@example.com');
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const CREATE_BILLING_ENTITY_MUTATION = <<<'GQL'
mutation($input: CreateBillingEntityInput!) {
    createBillingEntity(input: $input) {
        id
        code
        name
        legalName
        defaultCurrency
        country
        isDefault
        einvoicing
    }
}
GQL;

const UPDATE_BILLING_ENTITY_MUTATION = <<<'GQL'
mutation($input: UpdateBillingEntityInput!) {
    updateBillingEntity(input: $input) {
        id
        code
        name
        legalName
        addressLine1
        city
    }
}
GQL;

const DESTROY_BILLING_ENTITY_MUTATION = <<<'GQL'
mutation($input: DestroyBillingEntityInput!) {
    destroyBillingEntity(input: $input) { code }
}
GQL;

const BILLING_ENTITY_QUERY = <<<'GQL'
query($code: String!) {
    billingEntity(code: $code) { id code name defaultCurrency }
}
GQL;

const BILLING_ENTITIES_QUERY = <<<'GQL'
query {
    billingEntities {
        collection { id code name isDefault }
        metadata { totalCount }
    }
}
GQL;

it('creates a billing entity through createBillingEntity', function (): void {
    [$organization, $user] = gqlBillingEntitiesSetup();

    $payload = gqlPost(CREATE_BILLING_ENTITY_MUTATION, ['input' => [
        'name' => 'US Entity',
        'code' => 'us_entity',
        'legalName' => 'Acme US Inc.',
        'defaultCurrency' => 'USD',
        'country' => 'US',
        'einvoicing' => true,
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createBillingEntity');

    expect($payload['code'])->toBe('us_entity')
        ->and($payload['name'])->toBe('US Entity')
        ->and($payload['legalName'])->toBe('Acme US Inc.')
        ->and($payload['defaultCurrency'])->toBe('USD')
        ->and($payload['country'])->toBe('US');

    expect(BillingEntity::query()->where('organization_id', $organization->id)->count())->toBe(1);
})->group('gql:mutation:createBillingEntity');

it('updates a billing entity through updateBillingEntity', function (): void {
    [$organization, $user] = gqlBillingEntitiesSetup();

    $entity = BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'eu_entity',
        'name' => 'EU Entity',
    ]);

    $payload = gqlPost(UPDATE_BILLING_ENTITY_MUTATION, ['input' => [
        'id' => $entity->id,
        'name' => 'Renamed Entity',
        'legalName' => 'Acme EU BV',
        'addressLine1' => 'Dam 1',
        'city' => 'Amsterdam',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateBillingEntity');

    // The code is not assignable.
    expect($payload['code'])->toBe('eu_entity')
        ->and($payload['name'])->toBe('Renamed Entity')
        ->and($payload['legalName'])->toBe('Acme EU BV')
        ->and($payload['addressLine1'])->toBe('Dam 1')
        ->and($payload['city'])->toBe('Amsterdam');
})->group('gql:mutation:updateBillingEntity');

it('answers the default entity code through destroyBillingEntity (upstream stub)', function (): void {
    // Rails' Mutations::BillingEntities::Destroy is a stub: it takes a code
    // argument, destroys nothing and answers the organization's default
    // billing entity. Ported verbatim.
    [$organization, $user] = gqlBillingEntitiesSetup();

    $entity = BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'eu_entity',
    ]);

    $payload = gqlPost(DESTROY_BILLING_ENTITY_MUTATION, ['input' => ['code' => 'whatever']], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyBillingEntity');

    expect($payload['code'])->toBe($entity->code)
        ->and(BillingEntity::query()->where('organization_id', $organization->id)->count())->toBe(1);
})->group('gql:mutation:destroyBillingEntity');

it('fetches a single billing entity by code and answers not_found for an unknown code', function (): void {
    [$organization, $user] = gqlBillingEntitiesSetup();

    $entity = BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'eu_entity',
    ]);

    $payload = gqlPost(BILLING_ENTITY_QUERY, ['code' => 'eu_entity'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.billingEntity');

    expect($payload['id'])->toBe($entity->id)
        ->and($payload['code'])->toBe('eu_entity');

    gqlPost(BILLING_ENTITY_QUERY, ['code' => 'nonexistent'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');
})->group('gql:query:billingEntity');

it('lists the active billing entities', function (): void {
    [$organization, $user] = gqlBillingEntitiesSetup();

    BillingEntity::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'eu_entity',
    ]);
    BillingEntity::factory()->archived()->create([
        'organization_id' => $organization->id,
        'code' => 'archived_entity',
    ]);

    $payload = gqlPost(BILLING_ENTITIES_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.billingEntities');

    $codes = collect($payload['collection'])->pluck('code')->all();

    // The default entity of the fresh organization plus the active one; the
    // archived entity stays out (Rails: the active-scoped has_many).
    expect($codes)->toContain('eu_entity')
        ->and($codes)->not->toContain('archived_entity');
})->group('gql:query:billingEntities');
