<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Integrations\AnrokIntegration;
use App\Models\Integrations\AvalaraIntegration;
use App\Models\Organization;
use App\Models\User;

/**
 * Ports of Rails' spec/graphql/mutations/integrations/{anrok,avalara}/ and
 * the destroy mutation — the tax-integration CRUD surface over POST
 * /graphql, against the frozen-schema contract.
 */
function gqlIntegrationsSetup(): array
{
    $organization = gqlCreateOrganization();
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser('integrations@example.com');
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlPremiumOrganization(): Organization
{
    // Rails' :premium trait is the ENV license + premium_integrations flag;
    // the port's license gate reads config("lago.license").
    config()->set('lago.license', 'premium-token');

    $organization = gqlCreateOrganization('Premium Org');
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $organization->premium_integrations = ['avalara'];
    $organization->save();

    return $organization->refresh();
}

it('creates an anrok integration and obfuscates the api key', function (): void {
    [$organization, $user] = gqlIntegrationsSetup();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateAnrokIntegrationInput!) {
        createAnrokIntegration(input: $input) {
            id
            code
            name
            apiKey
            externalAccountId
        }
    }
    GQL, ['input' => [
        'code' => 'anrok1',
        'name' => 'Anrok 1',
        'apiKey' => '123/456/789',
        'connectionId' => 'this-is-random-uuid',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createAnrokIntegration');

    expect($payload['id'])->not->toBeNull()
        ->and($payload['code'])->toBe('anrok1')
        ->and($payload['name'])->toBe('Anrok 1')
        ->and($payload['apiKey'])->toBe('••••••••…789')
        ->and($payload['externalAccountId'])->toBe('123');

    $integration = AnrokIntegration::query()->firstOrFail();

    expect($integration->connectionId())->toBe('this-is-random-uuid');
});

it('updates an anrok integration', function (): void {
    [$organization, $user] = gqlIntegrationsSetup();
    config()->set('lago.license', 'premium-token');

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateAnrokIntegrationInput!) {
        updateAnrokIntegration(input: $input) {
            id
            name
            code
        }
    }
    GQL, ['input' => [
        'id' => $integration->id,
        'name' => 'Renamed',
        'code' => 'renamed_code',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateAnrokIntegration');

    expect($payload['id'])->toBe($integration->id)
        ->and($payload['name'])->toBe('Renamed')
        ->and($payload['code'])->toBe('renamed_code');

    $integration->refresh();

    expect($integration->name)->toBe('Renamed');
});

it('creates an avalara integration behind the premium flag', function (): void {
    $organization = gqlPremiumOrganization();
    $user = gqlCreateUser('avalara@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateAvalaraIntegrationInput!) {
        createAvalaraIntegration(input: $input) {
            id
            code
            name
            companyId
            licenseKey
        }
    }
    GQL, ['input' => [
        'code' => 'avalara1',
        'name' => 'Avalara 1',
        'accountId' => 'account1',
        'companyCode' => 'DEFAULT',
        'connectionId' => 'conn1',
        'licenseKey' => 'license1',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createAvalaraIntegration');

    expect($payload['code'])->toBe('avalara1')
        ->and($payload['companyId'])->toBeNull()
        ->and($payload['licenseKey'])->toBe('••••••••…se1');

    $integration = AvalaraIntegration::query()->firstOrFail();

    expect($integration->accountId())->toBe('account1');
});

it('refuses an avalara integration without the premium flag', function (): void {
    [$organization, $user] = gqlIntegrationsSetup();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateAvalaraIntegrationInput!) {
        createAvalaraIntegration(input: $input) {
            id
        }
    }
    GQL, ['input' => [
        'code' => 'avalara1',
        'name' => 'Avalara 1',
        'accountId' => 'account1',
        'companyCode' => 'DEFAULT',
        'connectionId' => 'conn1',
        'licenseKey' => 'license1',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createAvalaraIntegration'))->toBeNull()
        ->and(AvalaraIntegration::query()->count())->toBe(0);
});

it('destroys an integration', function (): void {
    [$organization, $user] = gqlIntegrationsSetup();

    $integration = AnrokIntegration::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(<<<'GQL'
    mutation($id: ID!) {
        destroyIntegration(id: $id) {
            id
        }
    }
    GQL, ['id' => $integration->id], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.destroyIntegration');

    expect($payload['id'])->toBe($integration->id)
        ->and(AnrokIntegration::query()->count())->toBe(0);
});
