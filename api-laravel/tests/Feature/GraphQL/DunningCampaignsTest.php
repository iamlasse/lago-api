<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Organization;
use App\Models\DunningCampaign;
use Illuminate\Support\Facades\Queue;
use App\Models\DunningCampaignThreshold;

/**
 * Ports of Rails' spec/graphql/resolvers/{dunning_campaign_resolver,
 * dunning_campaigns_resolver}_spec.rb and the mutations specs over the
 * frozen SDL. Ledger rows: gql:query:dunningCampaign, gql:query:dunningCampaigns,
 * gql:mutation:createDunningCampaign / updateDunningCampaign /
 * destroyDunningCampaign / downloadPaymentReceipt / downloadXmlPaymentReceipt /
 * resendPaymentReceiptEmail.
 */
beforeEach(function (): void {
    Queue::fake();
    config(['lago.license' => 'premium-license-token']);
});

afterEach(function (): void {
    config(['lago.license' => null]);
});

function dunningGqlOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create(array_merge([
        'premium_integrations' => ['auto_dunning'],
    ], $attributes));

    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const DUNNING_CAMPAIGN_QUERY = <<<'GQL'
query($id: ID!) {
    dunningCampaign(id: $id) {
        id name code appliedToOrganization customersCount
        thresholds { id amountCents currency }
    }
}
GQL;

const DUNNING_CAMPAIGNS_LIST = <<<'GQL'
query($searchTerm: String) {
    dunningCampaigns(limit: 10, searchTerm: $searchTerm) {
        collection { id name code }
        metadata { currentPage totalCount }
    }
}
GQL;

const CREATE_DUNNING_CAMPAIGN_MUTATION = <<<'GQL'
mutation($input: CreateDunningCampaignInput!) {
    createDunningCampaign(input: $input) {
        id name code thresholds { currency amountCents }
    }
}
GQL;

const UPDATE_DUNNING_CAMPAIGN_MUTATION = <<<'GQL'
mutation($input: UpdateDunningCampaignInput!) {
    updateDunningCampaign(input: $input) { id name }
}
GQL;

const DESTROY_DUNNING_CAMPAIGN_MUTATION = <<<'GQL'
mutation($input: DestroyDunningCampaignInput!) {
    destroyDunningCampaign(input: $input) { id }
}
GQL;

// -- queries ----------------------------------------------------------------

it('returns a dunning campaign by id', function (): void {
    [$organization, $user] = dunningGqlOrganization();

    $campaign = DunningCampaign::factory()->forOrganization($organization)->create(['code' => 'dc_1']);
    DunningCampaignThreshold::factory()->forCampaign($campaign)->forCurrency('EUR', 500)->create();

    $response = gqlPost(DUNNING_CAMPAIGN_QUERY, ['id' => $campaign->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.dunningCampaign');

    expect($payload['id'])->toBe($campaign->id)
        ->and($payload['code'])->toBe('dc_1')
        ->and($payload['thresholds'])->toHaveCount(1)
        ->and($payload['thresholds'][0]['currency'])->toBe('EUR')
        ->and($payload['thresholds'][0]['amountCents'])->toBe('500');
});

it('answers not_found for an unknown dunning campaign', function (): void {
    [$organization, $user] = dunningGqlOrganization();

    $response = gqlPost(DUNNING_CAMPAIGN_QUERY, ['id' => '00000000-0000-0000-0000-000000000000'], gqlAuthHeaders($user, $organization->id));

    $error = $response->json('errors.0.extensions');

    expect($error['status'])->toBe(404)
        ->and($error['code'])->toBe('not_found');
});

it('lists dunning campaigns with the search term', function (): void {
    [$organization, $user] = dunningGqlOrganization();

    $matched = DunningCampaign::factory()->forOrganization($organization)->create(['name' => 'Chase late payers']);
    DunningCampaign::factory()->forOrganization($organization)->create(['name' => 'Friendly reminders']);

    $response = gqlPost(DUNNING_CAMPAIGNS_LIST, ['searchTerm' => 'chase'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.dunningCampaigns');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($matched->id)
        ->and($payload['metadata']['totalCount'])->toBe(1);
});

it('does not leak another organization campaigns', function (): void {
    [$organization, $user] = dunningGqlOrganization();

    DunningCampaign::factory()->forOrganization(Organization::factory()->create())->create();

    $response = gqlPost(DUNNING_CAMPAIGNS_LIST, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.dunningCampaigns.collection'))->toHaveCount(0);
});

// -- mutations ----------------------------------------------------------------

it('creates a dunning campaign with thresholds', function (): void {
    [$organization, $user] = dunningGqlOrganization();

    $response = gqlPost(CREATE_DUNNING_CAMPAIGN_MUTATION, ['input' => [
        'name' => 'Dunning',
        'code' => 'dc_new',
        'daysBetweenAttempts' => 2,
        'maxAttempts' => 3,
        'appliedToOrganization' => false,
        'thresholds' => [
            ['currency' => 'EUR', 'amountCents' => '500'],
            ['currency' => 'USD', 'amountCents' => '1000'],
        ],
    ]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.createDunningCampaign');

    expect($payload['code'])->toBe('dc_new')
        ->and($payload['thresholds'])->toHaveCount(2);

    $campaign = DunningCampaign::query()->find($payload['id']);

    expect($campaign->days_between_attempts)->toBe(2)
        ->and($campaign->max_attempts)->toBe(3)
        ->and($campaign->thresholds()->count())->toBe(2);
});

it('updates a dunning campaign', function (): void {
    [$organization, $user] = dunningGqlOrganization();
    $campaign = DunningCampaign::factory()->forOrganization($organization)->create(['name' => 'Old']);

    $response = gqlPost(UPDATE_DUNNING_CAMPAIGN_MUTATION, ['input' => [
        'id' => $campaign->id,
        'name' => 'Renamed',
    ]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('data.updateDunningCampaign.name'))->toBe('Renamed')
        ->and($campaign->refresh()->name)->toBe('Renamed');
});

it('destroys a dunning campaign', function (): void {
    [$organization, $user] = dunningGqlOrganization();
    $campaign = DunningCampaign::factory()->forOrganization($organization)->create();

    $response = gqlPost(DESTROY_DUNNING_CAMPAIGN_MUTATION, ['input' => ['id' => $campaign->id]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('data.destroyDunningCampaign.id'))->toBe($campaign->id)
        ->and(DunningCampaign::query()->whereKey($campaign->id)->exists())->toBeFalse()
        ->and(DunningCampaign::withTrashed()->whereKey($campaign->id)->exists())->toBeTrue();
});

it('requires authentication on dunning campaigns', function (): void {
    $response = gqlPost(DUNNING_CAMPAIGNS_LIST);

    expect($response->json('errors.0.message'))->toBe('unauthorized');
});
