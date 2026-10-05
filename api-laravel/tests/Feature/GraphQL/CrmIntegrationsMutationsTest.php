<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use Illuminate\Support\Facades\Queue;
use App\Models\Integrations\HubspotIntegration;
use App\Jobs\Integrations\Hubspot\SavePortalIdJob;
use App\Models\Integrations\SalesforceIntegration;

/**
 * Ports of Rails' spec/graphql/mutations/integrations/{salesforce,hubspot}/
 * — the CRM integration CRUD surface over POST /graphql, against the
 * frozen-schema contract.
 */
function gqlCrmOrganization(string $premiumIntegration): object
{
    // Rails' :premium trait is the ENV license + premium_integrations flag.
    config()->set('lago.license', 'premium-token');

    $organization = gqlCreateOrganization('CRM Org');
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $organization->premium_integrations = [$premiumIntegration];
    $organization->save();

    return $organization->refresh();
}

function gqlCrmNoPremiumOrganization(): object
{
    // No premium license: the create must be refused.
    config()->set('lago.license', null);

    $organization = gqlCreateOrganization('CRM Free Org');
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);

    return $organization->refresh();
}

it('creates a salesforce integration', function (): void {
    $organization = gqlCrmOrganization('salesforce');
    $user = gqlCreateUser('salesforce@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateSalesforceIntegrationInput!) {
        createSalesforceIntegration(input: $input) {
            id
            code
            name
            instanceId
        }
    }
    GQL, ['input' => [
        'code' => 'salesforce1',
        'name' => 'Salesforce 1',
        'instanceId' => 'Instance1',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createSalesforceIntegration');

    expect($payload['id'])->not->toBeNull()
        ->and($payload['code'])->toBe('salesforce1')
        ->and($payload['name'])->toBe('Salesforce 1')
        ->and($payload['instanceId'])->toBe('Instance1');

    $integration = SalesforceIntegration::query()->firstOrFail();

    expect($integration->instanceId())->toBe('Instance1');
});

it('refuses a salesforce integration without the premium flag', function (): void {
    $organization = gqlCrmNoPremiumOrganization();
    $user = gqlCreateUser('salesforce-free@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateSalesforceIntegrationInput!) {
        createSalesforceIntegration(input: $input) {
            id
        }
    }
    GQL, ['input' => [
        'code' => 'salesforce1',
        'name' => 'Salesforce 1',
        'instanceId' => 'Instance1',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createSalesforceIntegration'))->toBeNull()
        ->and(SalesforceIntegration::query()->count())->toBe(0);
});

it('updates a salesforce integration', function (): void {
    $organization = gqlCrmOrganization('salesforce');
    $user = gqlCreateUser('salesforce-update@example.com');
    gqlCreateMembership($user, $organization);

    $integration = SalesforceIntegration::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateSalesforceIntegrationInput!) {
        updateSalesforceIntegration(input: $input) {
            id
            name
            code
            instanceId
        }
    }
    GQL, ['input' => [
        'id' => $integration->id,
        'name' => 'Renamed',
        'code' => 'renamed_code',
        'instanceId' => 'Instance2',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateSalesforceIntegration');

    expect($payload['id'])->toBe($integration->id)
        ->and($payload['name'])->toBe('Renamed')
        ->and($payload['code'])->toBe('renamed_code')
        ->and($payload['instanceId'])->toBe('Instance2');

    $integration->refresh();

    expect($integration->instanceId())->toBe('Instance2');
});

it('creates a hubspot integration and enqueues the portal id job', function (): void {
    $organization = gqlCrmOrganization('hubspot');
    $user = gqlCreateUser('hubspot@example.com');
    gqlCreateMembership($user, $organization);
    Queue::fake();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateHubspotIntegrationInput!) {
        createHubspotIntegration(input: $input) {
            id
            code
            name
            connectionId
            defaultTargetedObject
            syncInvoices
            syncSubscriptions
        }
    }
    GQL, ['input' => [
        'code' => 'hubspot1',
        'name' => 'Hubspot 1',
        'connectionId' => 'this-is-random-uuid',
        'defaultTargetedObject' => 'companies',
        'syncInvoices' => true,
        'syncSubscriptions' => false,
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createHubspotIntegration');

    expect($payload['id'])->not->toBeNull()
        ->and($payload['code'])->toBe('hubspot1')
        ->and($payload['name'])->toBe('Hubspot 1')
        ->and($payload['connectionId'])->toBe('this-is-random-uuid')
        ->and($payload['defaultTargetedObject'])->toBe('companies')
        ->and($payload['syncInvoices'])->toBeTrue()
        ->and($payload['syncSubscriptions'])->toBeFalse();

    $integration = HubspotIntegration::query()->firstOrFail();

    Queue::assertPushed(SavePortalIdJob::class, fn (SavePortalIdJob $job) => $job->integration->id === $integration->id);
});

it('refuses a hubspot integration without the premium flag', function (): void {
    $organization = gqlCrmNoPremiumOrganization();
    $user = gqlCreateUser('hubspot-free@example.com');
    gqlCreateMembership($user, $organization);

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateHubspotIntegrationInput!) {
        createHubspotIntegration(input: $input) {
            id
        }
    }
    GQL, ['input' => [
        'code' => 'hubspot1',
        'name' => 'Hubspot 1',
        'connectionId' => 'this-is-random-uuid',
        'defaultTargetedObject' => 'companies',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createHubspotIntegration'))->toBeNull()
        ->and(HubspotIntegration::query()->count())->toBe(0);
});

it('updates a hubspot integration', function (): void {
    $organization = gqlCrmOrganization('hubspot');
    $user = gqlCreateUser('hubspot-update@example.com');
    gqlCreateMembership($user, $organization);

    $integration = HubspotIntegration::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateHubspotIntegrationInput!) {
        updateHubspotIntegration(input: $input) {
            id
            name
            code
            defaultTargetedObject
            syncInvoices
            syncSubscriptions
        }
    }
    GQL, ['input' => [
        'id' => $integration->id,
        'name' => 'Renamed',
        'code' => 'renamed_code',
        'defaultTargetedObject' => 'contacts',
        'syncInvoices' => false,
        'syncSubscriptions' => true,
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateHubspotIntegration');

    expect($payload['id'])->toBe($integration->id)
        ->and($payload['name'])->toBe('Renamed')
        ->and($payload['code'])->toBe('renamed_code')
        ->and($payload['defaultTargetedObject'])->toBe('contacts')
        ->and($payload['syncInvoices'])->toBeFalse()
        ->and($payload['syncSubscriptions'])->toBeTrue();

    $integration->refresh();

    expect($integration->defaultTargetedObject())->toBe('contacts');
});
