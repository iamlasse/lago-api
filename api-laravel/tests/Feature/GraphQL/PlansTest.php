<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Plan;

/**
 * Port of the Rails plans resolver specs over the frozen SDL
 * (spec/graphql/resolvers/plans_resolver_spec.rb semantics): the Lago front's
 * Plans page query — collection + metadata with the search term, pagination
 * and the with_deleted filter.
 *
 * Ledger rows: gql:query:plans.
 */
function gqlPlansSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser('plans@example.com');

    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const PLANS_QUERY = <<<'GQL'
query($page: Int, $limit: Int, $searchTerm: String, $withDeleted: Boolean) {
    plans(page: $page, limit: $limit, searchTerm: $searchTerm, withDeleted: $withDeleted) {
        collection { id code name amountCents amountCurrency interval }
        metadata { currentPage limitValue totalPages totalCount }
    }
}
GQL;

it('lists plans of the organization with pagination metadata', function (): void {
    [$organization, $user] = gqlPlansSetup();

    $plan = Plan::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Basic plan',
        'code' => 'basic',
    ]);

    $payload = gqlPost(PLANS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    // Kaminari defaults apply when the GraphQL arguments are absent:
    // page 1, 25 per page.
    expect(count($payload['collection']))->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($plan->id)
        ->and($payload['collection'][0]['code'])->toBe('basic')
        ->and($payload['collection'][0]['amountCents'])->toBe('100')
        ->and($payload['collection'][0]['amountCurrency'])->toBe('EUR')
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['limitValue'])->toBe(25)
        ->and($payload['metadata']['totalPages'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(1);
});

it('filters plans by the search term on name and code', function (): void {
    [$organization, $user] = gqlPlansSetup();

    Plan::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Seats plan',
        'code' => 'seats',
    ]);

    Plan::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Other plan',
        'code' => 'other_code',
    ]);

    $byName = gqlPost(PLANS_QUERY, ['searchTerm' => 'Seats'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    expect(count($byName['collection']))->toBe(1)
        ->and($byName['collection'][0]['code'])->toBe('seats')
        ->and($byName['metadata']['totalCount'])->toBe(1);

    $byCode = gqlPost(PLANS_QUERY, ['searchTerm' => 'other_code'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    expect(count($byCode['collection']))->toBe(1)
        ->and($byCode['collection'][0]['code'])->toBe('other_code');

    $miss = gqlPost(PLANS_QUERY, ['searchTerm' => 'nope'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    expect(count($miss['collection']))->toBe(0)
        ->and($miss['metadata']['totalCount'])->toBe(0)
        // Kaminari reports at least one page, even for an empty set.
        ->and($miss['metadata']['totalPages'])->toBe(1);
});

it('paginates plans and keeps the requested page in the metadata', function (): void {
    [$organization, $user] = gqlPlansSetup();

    Plan::factory()->create(['organization_id' => $organization->id, 'name' => 'A plan', 'code' => 'a']);
    Plan::factory()->create(['organization_id' => $organization->id, 'name' => 'B plan', 'code' => 'b']);

    $pageOne = gqlPost(PLANS_QUERY, ['page' => 1, 'limit' => 1], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    expect(count($pageOne['collection']))->toBe(1)
        ->and($pageOne['metadata']['limitValue'])->toBe(1)
        ->and($pageOne['metadata']['totalPages'])->toBe(2)
        ->and($pageOne['metadata']['totalCount'])->toBe(2);

    // An out-of-range page yields an empty collection while the metadata
    // keeps the requested page and the real totals, exactly like kaminari.
    $outOfRange = gqlPost(PLANS_QUERY, ['page' => 5, 'limit' => 1], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    expect(count($outOfRange['collection']))->toBe(0)
        ->and($outOfRange['metadata']['currentPage'])->toBe(5)
        ->and($outOfRange['metadata']['totalCount'])->toBe(2);
});

it('excludes deleted plans unless withDeleted is passed', function (): void {
    [$organization, $user] = gqlPlansSetup();

    Plan::factory()->create(['organization_id' => $organization->id, 'name' => 'Kept plan', 'code' => 'kept']);
    Plan::factory()->create(['organization_id' => $organization->id, 'name' => 'Gone plan', 'code' => 'gone'])
        ->delete();

    $kept = gqlPost(PLANS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    expect(count($kept['collection']))->toBe(1)
        ->and($kept['collection'][0]['code'])->toBe('kept');

    $withDeleted = gqlPost(PLANS_QUERY, ['withDeleted' => true], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.plans');

    expect(count($withDeleted['collection']))->toBe(2)
        ->and($withDeleted['metadata']['totalCount'])->toBe(2);
});

it('answers unauthorized without a signed-in user', function (): void {
    $organization = gqlCreateOrganization();

    gqlPost(PLANS_QUERY, [], ['x-lago-organization' => $organization->id])
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'unauthorized');
});

// -- mutation createPlan ------------------------------------------------------------------

const CREATE_PLAN_MUTATION = <<<'GQL'
mutation($input: CreatePlanInput!) {
    createPlan(input: $input) {
        id
        code
        name
        interval
        amountCents
        amountCurrency
        payInAdvance
    }
}
GQL;

it('creates a plan through createPlan', function (): void {
    [$organization, $user] = gqlPlansSetup();

    $payload = gqlPost(CREATE_PLAN_MUTATION, ['input' => [
        'name' => 'Basic plan',
        'code' => 'basic',
        'interval' => 'monthly',
        'amountCents' => 4990,
        'amountCurrency' => 'EUR',
        'payInAdvance' => false,
        'charges' => [],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createPlan');

    expect($payload['code'])->toBe('basic')
        ->and($payload['name'])->toBe('Basic plan')
        ->and($payload['interval'])->toBe('monthly')
        ->and($payload['amountCents'])->toBe('4990')
        ->and($payload['amountCurrency'])->toBe('EUR');

    expect(Plan::query()->where('organization_id', $organization->id)->count())->toBe(1);
})->group('gql:mutation:createPlan');

it('answers a validation error on a duplicated plan code', function (): void {
    [$organization, $user] = gqlPlansSetup();

    Plan::factory()->create(['organization_id' => $organization->id, 'code' => 'basic']);

    $response = gqlPost(CREATE_PLAN_MUTATION, ['input' => [
        'name' => 'Another basic',
        'code' => 'basic',
        'interval' => 'monthly',
        'amountCents' => 100,
        'amountCurrency' => 'EUR',
        'charges' => [],
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors'))->toBeArray()
        ->and($response->json('data.createPlan'))->toBeNull();
});
