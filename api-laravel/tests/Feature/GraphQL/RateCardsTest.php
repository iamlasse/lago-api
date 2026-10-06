<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Product;
use App\Models\RateCard;
use App\Models\CatalogPlan;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\PlanRateCard;
use App\Models\RateCardRate;

/**
 * Ports of Rails' spec/graphql/mutations/rate_cards/*_spec.rb,
 * rate_card_rates/*_spec.rb, rate_phases/*_spec.rb and the rate card
 * resolvers over the frozen SDL.
 *
 * Ledger rows: gql:query:rateCard, gql:query:rateCards, gql:query:rateCardRate,
 * gql:query:rateCardRates, gql:mutation:createRateCard,
 * gql:mutation:updateRateCard, gql:mutation:destroyRateCard,
 * gql:mutation:createRateCardRate, gql:mutation:updateRateCardRate,
 * gql:mutation:destroyRateCardRate, gql:mutation:createRatePhase,
 * gql:mutation:updateRatePhase, gql:mutation:destroyRatePhase.
 */
function gqlRateCardsSetup(): array
{
    $organization = Organization::factory()->create(['feature_flags' => ['product_catalog']]);
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const CREATE_RATE_CARD_MUTATION = <<<'GQL'
mutation($input: CreateRateCardInput!) {
    createRateCard(input: $input) { id code name currency billingTiming }
}
GQL;

const RATE_CARDS_QUERY = <<<'GQL'
query($page: Int, $limit: Int, $searchTerm: String, $code: String) {
    rateCards(page: $page, limit: $limit, searchTerm: $searchTerm, code: $code) {
        collection { id code name }
        metadata { totalCount }
    }
}
GQL;

const RATE_CARD_QUERY = <<<'GQL'
query($id: ID!) {
    rateCard(id: $id) { id code name }
}
GQL;

const UPDATE_RATE_CARD_MUTATION = <<<'GQL'
mutation($input: UpdateRateCardInput!) {
    updateRateCard(input: $input) { id name }
}
GQL;

const DESTROY_RATE_CARD_MUTATION = <<<'GQL'
mutation($input: DestroyRateCardInput!) {
    destroyRateCard(input: $input) { id }
}
GQL;

const CREATE_RATE_CARD_RATE_MUTATION = <<<'GQL'
mutation($input: CreateRateCardRateInput!) {
    createRateCardRate(input: $input) { id code rateModel }
}
GQL;

const RATE_CARD_RATES_QUERY = <<<'GQL'
query($rateCardId: ID!) {
    rateCardRates(rateCardId: $rateCardId) {
        collection { id code rateModel }
        metadata { totalCount }
    }
}
GQL;

const RATE_CARD_RATE_QUERY = <<<'GQL'
query($id: ID!) {
    rateCardRate(id: $id) { id code }
}
GQL;

const UPDATE_RATE_CARD_RATE_MUTATION = <<<'GQL'
mutation($input: UpdateRateCardRateInput!) {
    updateRateCardRate(input: $input) { id code }
}
GQL;

const DESTROY_RATE_CARD_RATE_MUTATION = <<<'GQL'
mutation($input: DestroyRateCardRateInput!) {
    destroyRateCardRate(input: $input) { id }
}
GQL;

const CREATE_RATE_PHASE_MUTATION = <<<'GQL'
mutation($input: CreateRatePhaseInput!) {
    createRatePhase(input: $input) { id code }
}
GQL;

const UPDATE_RATE_PHASE_MUTATION = <<<'GQL'
mutation($input: UpdateRatePhaseInput!) {
    updateRatePhase(input: $input) { id code }
}
GQL;

const DESTROY_RATE_PHASE_MUTATION = <<<'GQL'
mutation($input: DestroyRatePhaseInput!) {
    destroyRatePhase(input: $input) { id }
}
GQL;

function gqlRateCardRateInput(string $rateCardId, string $code): array
{
    return [
        'rateCardId' => $rateCardId,
        'code' => $code,
        'rateModel' => 'standard',
        'billingIntervalUnit' => 'month',
        'effectiveFrom' => '2027-01-01T00:00:00Z',
        'rateProperties' => ['amount' => '10'],
    ];
}

it('creates, fetches, updates and destroys a rate card', function (): void {
    [$organization, $user] = gqlRateCardsSetup();

    $product = Product::factory()->create(['organization_id' => $organization->id]);

    $id = gqlPost(CREATE_RATE_CARD_MUTATION, ['input' => [
        'productId' => $product->id,
        'name' => 'Card 1',
        'code' => 'card_1',
        'currency' => 'EUR',
        'billingTiming' => 'arrears',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createRateCard.id');

    expect($id)->not->toBeNull();

    expect(gqlPost(RATE_CARD_QUERY, ['id' => $id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.rateCard.code'))->toBe('card_1');

    // An unknown id answers the not_found error envelope.
    gqlPost(RATE_CARD_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_RATE_CARD_MUTATION, ['input' => [
        'id' => $id,
        'name' => 'Renamed card',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateRateCard.name'))
        ->toBe('Renamed card');

    expect(gqlPost(DESTROY_RATE_CARD_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyRateCard.id'))->toBe($id);
});

it('lists rate cards through rateCards with the search term and code', function (): void {
    [$organization, $user] = gqlRateCardsSetup();

    $product = Product::factory()->create(['organization_id' => $organization->id]);

    RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'name' => 'Founding fee card',
        'code' => 'founding_card',
    ]);

    RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'name' => 'Other card',
        'code' => 'other_card',
    ]);

    $payload = gqlPost(RATE_CARDS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.rateCards');

    expect(count($payload['collection']))->toBe(2);

    $found = gqlPost(RATE_CARDS_QUERY, ['searchTerm' => 'founding'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.rateCards');

    expect(count($found['collection']))->toBe(1)
        ->and($found['collection'][0]['code'])->toBe('founding_card');

    $byCode = gqlPost(RATE_CARDS_QUERY, ['code' => 'other_card'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.rateCards');

    expect(count($byCode['collection']))->toBe(1)
        ->and($byCode['collection'][0]['code'])->toBe('other_card');
});

it('answers 403 feature_unavailable without the product_catalog flag', function (): void {
    $organization = Organization::factory()->create(['feature_flags' => []]);
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    gqlPost(RATE_CARDS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'feature_unavailable');

    gqlPost(RATE_CARD_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'feature_unavailable');
});

it('creates, lists, updates and destroys a rate card rate', function (): void {
    [$organization, $user] = gqlRateCardsSetup();

    $product = Product::factory()->create(['organization_id' => $organization->id]);

    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
    ]);

    $id = gqlPost(CREATE_RATE_CARD_RATE_MUTATION, ['input' => gqlRateCardRateInput($rateCard->id, 'rate_1')], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.createRateCardRate.id');

    expect($id)->not->toBeNull();

    $listed = gqlPost(RATE_CARD_RATES_QUERY, ['rateCardId' => $rateCard->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.rateCardRates');

    expect(count($listed['collection']))->toBe(1)
        ->and($listed['collection'][0]['code'])->toBe('rate_1');

    // An unknown id answers the not_found error envelope.
    gqlPost(RATE_CARD_RATE_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_RATE_CARD_RATE_MUTATION, ['input' => [
        'id' => $id,
        'code' => 'rate_1',
        'rateProperties' => ['amount' => '42'],
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateRateCardRate.id'))
        ->toBe($id);

    expect(gqlPost(DESTROY_RATE_CARD_RATE_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyRateCardRate.id'))->toBe($id);
});

it('creates, updates and destroys a rate phase on a plan applied rate card', function (): void {
    [$organization, $user] = gqlRateCardsSetup();

    $product = Product::factory()->create(['organization_id' => $organization->id]);

    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
    ]);

    $catalogPlan = CatalogPlan::factory()->create(['organization_id' => $organization->id]);

    $planRateCard = PlanRateCard::factory()->create([
        'organization_id' => $organization->id,
        'catalog_plan_id' => $catalogPlan->id,
        'rate_card_id' => $rateCard->id,
    ]);

    // The last phase of the sequence is the indefinite (cycle-count-less)
    // terminal one — Rails refuses other shapes, so the tail is created
    // first and the finite phase inserted before it.
    $tailId = gqlPost(CREATE_RATE_PHASE_MUTATION, ['input' => [
        'planAppliedRateCardId' => $planRateCard->id,
        'code' => 'phase_tail',
        'name' => 'Terminal phase',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createRatePhase.id');

    $id = gqlPost(CREATE_RATE_PHASE_MUTATION, ['input' => [
        'planAppliedRateCardId' => $planRateCard->id,
        'code' => 'phase_1',
        'name' => 'Intro phase',
        'position' => 1,
        'billingIntervalCycleCount' => 3,
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createRatePhase.id');

    expect($id)->not->toBeNull()->and($tailId)->not->toBeNull();

    expect($id)->not->toBeNull();

    expect(gqlPost(UPDATE_RATE_PHASE_MUTATION, ['input' => [
        'planAppliedRateCardId' => $planRateCard->id,
        'code' => 'phase_1',
        'name' => 'Renamed phase',
        'newCode' => 'phase_renamed',
        'billingIntervalCycleCount' => 4,
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateRatePhase.code'))
        ->toBe('phase_renamed');

    expect(gqlPost(DESTROY_RATE_PHASE_MUTATION, ['input' => [
        'planAppliedRateCardId' => $planRateCard->id,
        'code' => 'phase_renamed',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.destroyRatePhase.id'))
        ->toBe($id);
});
