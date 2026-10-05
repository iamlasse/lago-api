<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\PricingUnit;
use App\Models\Organization;

/**
 * Ports of the pricing units GraphQL surface over the frozen SDL
 * (Rails: mutations/pricing_units/{create,update}, resolvers
 * {pricing_unit,pricing_units}).
 *
 * Ledger rows: gql:mutation:createPricingUnit, gql:mutation:updatePricingUnit,
 * gql:query:pricingUnit, gql:query:pricingUnits.
 */
function gqlPricingUnitsSetup(): array
{
    // The whole surface is premium-gated (License.premium? in both services).
    config(['lago.license' => 'premium-license-token']);

    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('pricing-units@example.com');
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlPricingUnit(Organization $organization, array $attributes = []): PricingUnit
{
    return PricingUnit::create(array_merge([
        'organization_id' => $organization->id,
        'name' => 'Credits',
        'code' => 'credits',
        'short_name' => 'cr',
    ], $attributes))->refresh();
}

const CREATE_PRICING_UNIT_MUTATION = <<<'GQL'
mutation($input: CreatePricingUnitInput!) {
    createPricingUnit(input: $input) { id code name shortName description }
}
GQL;

const UPDATE_PRICING_UNIT_MUTATION = <<<'GQL'
mutation($input: UpdatePricingUnitInput!) {
    updatePricingUnit(input: $input) { id code name shortName description }
}
GQL;

const PRICING_UNIT_QUERY = <<<'GQL'
query($id: ID!) {
    pricingUnit(id: $id) { id code name shortName }
}
GQL;

const PRICING_UNITS_QUERY = <<<'GQL'
query($page: Int, $limit: Int, $searchTerm: String) {
    pricingUnits(page: $page, limit: $limit, searchTerm: $searchTerm) {
        collection { id code name shortName }
        metadata { currentPage totalCount }
    }
}
GQL;

it('creates a pricing unit through createPricingUnit', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();

    $payload = gqlPost(CREATE_PRICING_UNIT_MUTATION, ['input' => [
        'name' => 'Tokens',
        'code' => 'tokens',
        'shortName' => 'tk',
        'description' => 'LLM tokens',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createPricingUnit');

    expect($payload['code'])->toBe('tokens')
        ->and($payload['name'])->toBe('Tokens')
        ->and($payload['shortName'])->toBe('tk')
        ->and($payload['description'])->toBe('LLM tokens');

    expect(PricingUnit::query()->where('organization_id', $organization->id)->count())->toBe(1);
})->group('gql:mutation:createPricingUnit');

it('answers a validation error on a duplicated pricing unit code', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();
    gqlPricingUnit($organization);

    $response = gqlPost(CREATE_PRICING_UNIT_MUTATION, ['input' => [
        'name' => 'Credits again',
        'code' => 'credits',
        'shortName' => 'cr',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeArray()
        ->and($response->json('data.createPricingUnit'))->toBeNull();
});

it('answers forbidden on the premium gate without a license', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();
    config(['lago.license' => null]);

    $response = gqlPost(CREATE_PRICING_UNIT_MUTATION, ['input' => [
        'name' => 'Tokens',
        'code' => 'tokens',
        'shortName' => 'tk',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('feature_unavailable');
});

it('updates a pricing unit through updatePricingUnit', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();
    $pricingUnit = gqlPricingUnit($organization, ['description' => 'before']);

    $payload = gqlPost(UPDATE_PRICING_UNIT_MUTATION, ['input' => [
        'id' => $pricingUnit->id,
        'name' => 'Renamed',
        'shortName' => 'rn',
        'description' => 'after',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updatePricingUnit');

    // The code is not assignable (Rails: params.slice name/short_name/description).
    expect($payload['code'])->toBe('credits')
        ->and($payload['name'])->toBe('Renamed')
        ->and($payload['shortName'])->toBe('rn')
        ->and($payload['description'])->toBe('after');
})->group('gql:mutation:updatePricingUnit');

it('answers not_found when updating an unknown pricing unit', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();

    $response = gqlPost(UPDATE_PRICING_UNIT_MUTATION, ['input' => [
        'id' => '00000000-0000-0000-0000-000000000000',
        'name' => 'Renamed',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.status'))->toBe(404)
        ->and($response->json('errors.0.extensions.code'))->toBe('not_found');
});

it('answers forbidden on the update premium gate without a license', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();
    $pricingUnit = gqlPricingUnit($organization);
    config(['lago.license' => null]);

    $response = gqlPost(UPDATE_PRICING_UNIT_MUTATION, ['input' => [
        'id' => $pricingUnit->id,
        'name' => 'Renamed',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('feature_unavailable');
});

it('fetches a single pricing unit and answers not_found for an unknown id', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();
    $pricingUnit = gqlPricingUnit($organization);

    $payload = gqlPost(PRICING_UNIT_QUERY, ['id' => $pricingUnit->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.pricingUnit');

    expect($payload['code'])->toBe('credits')
        ->and($payload['shortName'])->toBe('cr');

    gqlPost(PRICING_UNIT_QUERY, ['id' => '00000000-0000-0000-0000-000000000000'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');
})->group('gql:query:pricingUnit');

it('lists and searches the organization pricing units', function (): void {
    [$organization, $user] = gqlPricingUnitsSetup();
    gqlPricingUnit($organization);
    gqlPricingUnit($organization, ['name' => 'Tokens', 'code' => 'tokens', 'short_name' => 'tk']);

    $payload = gqlPost(PRICING_UNITS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.pricingUnits');

    expect(count($payload['collection']))->toBe(2)
        ->and($payload['metadata']['totalCount'])->toBe(2);

    $searched = gqlPost(PRICING_UNITS_QUERY, ['searchTerm' => 'tok'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.pricingUnits');

    expect(count($searched['collection']))->toBe(1)
        ->and($searched['collection'][0]['code'])->toBe('tokens');
})->group('gql:query:pricingUnits');
