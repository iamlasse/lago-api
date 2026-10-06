<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\CatalogPlan;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\PlanRateCard;
use App\Models\RateCard;
use Illuminate\Support\Str;

/**
 * Ports of Rails' spec/graphql/mutations/contracts/*_spec.rb,
 * plan_applied_rate_cards/*_spec.rb, contract_applied_rate_cards/*_spec.rb and
 * the contract resolvers over the frozen SDL.
 *
 * Ledger rows: gql:query:contract, gql:query:contracts,
 * gql:query:planAppliedRateCards, gql:query:contractAppliedRateCards,
 * gql:mutation:createPlanAppliedRateCard, gql:mutation:destroyPlanAppliedRateCard,
 * gql:mutation:createContractAppliedRateCard,
 * gql:mutation:destroyContractAppliedRateCard, gql:mutation:createContract,
 * gql:mutation:updateContract, gql:mutation:terminateContract.
 */
function gqlContractsSetup(): array
{
    $organization = Organization::factory()->create(['feature_flags' => ['product_catalog']]);
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const CREATE_CONTRACT_MUTATION = <<<'GQL'
mutation($input: CreateContractInput!) {
    createContract(input: $input) { id externalId name status }
}
GQL;

const CONTRACTS_QUERY = <<<'GQL'
query($externalId: String, $externalCustomerId: String, $searchTerm: String) {
    contracts(externalId: $externalId, externalCustomerId: $externalCustomerId, searchTerm: $searchTerm) {
        collection { id externalId name status }
        metadata { totalCount }
    }
}
GQL;

const CONTRACT_QUERY = <<<'GQL'
query($id: ID!) {
    contract(id: $id) { id externalId }
}
GQL;

const UPDATE_CONTRACT_MUTATION = <<<'GQL'
mutation($input: UpdateContractInput!) {
    updateContract(input: $input) { id name }
}
GQL;

const TERMINATE_CONTRACT_MUTATION = <<<'GQL'
mutation($input: TerminateContractInput!) {
    terminateContract(input: $input) { id status }
}
GQL;

const CREATE_PLAN_APPLIED_RATE_CARD_MUTATION = <<<'GQL'
mutation($input: CreatePlanAppliedRateCardInput!) {
    createPlanAppliedRateCard(input: $input) { id rateCardCode }
}
GQL;

const PLAN_APPLIED_RATE_CARDS_QUERY = <<<'GQL'
query($planId: ID) {
    planAppliedRateCards(planId: $planId) {
        collection { id rateCardCode }
        metadata { totalCount }
    }
}
GQL;

const DESTROY_PLAN_APPLIED_RATE_CARD_MUTATION = <<<'GQL'
mutation($input: DestroyPlanAppliedRateCardInput!) {
    destroyPlanAppliedRateCard(input: $input) { id }
}
GQL;

const CREATE_CONTRACT_APPLIED_RATE_CARD_MUTATION = <<<'GQL'
mutation($input: CreateContractAppliedRateCardInput!) {
    createContractAppliedRateCard(input: $input) { id rateCardCode }
}
GQL;

const CONTRACT_APPLIED_RATE_CARDS_QUERY = <<<'GQL'
query($contractId: ID) {
    contractAppliedRateCards(contractId: $contractId) {
        collection { id rateCardCode }
        metadata { totalCount }
    }
}
GQL;

const DESTROY_CONTRACT_APPLIED_RATE_CARD_MUTATION = <<<'GQL'
mutation($input: DestroyContractAppliedRateCardInput!) {
    destroyContractAppliedRateCard(input: $input) { id }
}
GQL;

function gqlPendingContract(Organization $organization): Contract
{
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'cust_'.Str::uuid(),
        'currency' => 'EUR',
    ]);

    return Contract::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => 'pending',
        'started_at' => now()->addDays(5),
    ]);
}

it('creates, fetches, updates and terminates a contract', function (): void {
    [$organization, $user] = gqlContractsSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'cust_'.Str::uuid(),
    ]);

    $externalId = 'contract_'.Str::uuid();

    $payload = gqlPost(CREATE_CONTRACT_MUTATION, ['input' => [
        'externalCustomerId' => $customer->external_id,
        'externalId' => $externalId,
        'name' => 'Acme contract',
        'billingTime' => 'calendar',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createContract');

    expect($payload['externalId'])->toBe($externalId)
        ->and($payload['status'])->toBe('pending');

    // An unknown id answers the not_found error envelope.
    gqlPost(CONTRACT_QUERY, ['id' => Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    $contractId = $payload['id'];

    expect(gqlPost(CONTRACT_QUERY, ['id' => $contractId], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.contract.externalId'))->toBe($externalId);

    $listed = gqlPost(CONTRACTS_QUERY, ['externalId' => $externalId], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.contracts');

    expect(count($listed['collection']))->toBe(1)
        ->and($listed['collection'][0]['name'])->toBe('Acme contract');

    expect(gqlPost(UPDATE_CONTRACT_MUTATION, ['input' => [
        'externalId' => $externalId,
        'name' => 'Renamed contract',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateContract.name'))
        ->toBe('Renamed contract');

    $terminated = gqlPost(TERMINATE_CONTRACT_MUTATION, ['input' => [
        'externalId' => $externalId,
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.terminateContract');

    expect($terminated['status'])->toBe('terminated');
});

it('lists contracts through contracts with the customer and search filters', function (): void {
    [$organization, $user] = gqlContractsSetup();

    $contract = gqlPendingContract($organization);

    $byCustomer = gqlPost(CONTRACTS_QUERY, ['externalCustomerId' => $contract->customer->external_id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.contracts');

    expect(count($byCustomer['collection']))->toBe(1);

    $bySearch = gqlPost(CONTRACTS_QUERY, ['searchTerm' => $contract->external_id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.contracts');

    expect(count($bySearch['collection']))->toBe(1);
});

it('applies and removes a rate card on a plan', function (): void {
    [$organization, $user] = gqlContractsSetup();

    $product = Product::factory()->create(['organization_id' => $organization->id]);

    $rateCard = RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'code' => 'plan_card',
    ]);

    $catalogPlan = CatalogPlan::factory()->create(['organization_id' => $organization->id]);

    $payload = gqlPost(CREATE_PLAN_APPLIED_RATE_CARD_MUTATION, ['input' => [
        'planId' => $catalogPlan->id,
        'rateCardCode' => 'plan_card',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createPlanAppliedRateCard');

    expect($payload['rateCardCode'])->toBe('plan_card');

    $listed = gqlPost(PLAN_APPLIED_RATE_CARDS_QUERY, ['planId' => $catalogPlan->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.planAppliedRateCards');

    expect(count($listed['collection']))->toBe(1);

    expect(gqlPost(DESTROY_PLAN_APPLIED_RATE_CARD_MUTATION, ['input' => ['id' => $payload['id']]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyPlanAppliedRateCard.id'))->toBe($payload['id']);
});

it('attaches and removes a rate card on a pending contract', function (): void {
    [$organization, $user] = gqlContractsSetup();

    $product = Product::factory()->create(['organization_id' => $organization->id]);

    RateCard::factory()->create([
        'organization_id' => $organization->id,
        'product_id' => $product->id,
        'code' => 'contract_card',
    ]);

    $contract = gqlPendingContract($organization);

    $payload = gqlPost(CREATE_CONTRACT_APPLIED_RATE_CARD_MUTATION, ['input' => [
        'externalId' => $contract->external_id,
        'rateCardCode' => 'contract_card',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createContractAppliedRateCard');

    expect($payload['rateCardCode'])->toBe('contract_card');

    $listed = gqlPost(CONTRACT_APPLIED_RATE_CARDS_QUERY, ['contractId' => $contract->id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.contractAppliedRateCards');

    expect(count($listed['collection']))->toBe(1)
        ->and($listed['collection'][0]['rateCardCode'])->toBe('contract_card');

    expect(gqlPost(DESTROY_CONTRACT_APPLIED_RATE_CARD_MUTATION, ['input' => ['id' => $payload['id']]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyContractAppliedRateCard.id'))->toBe($payload['id']);
});
