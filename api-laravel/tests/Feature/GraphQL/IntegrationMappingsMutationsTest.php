<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\AddOn;
use App\Models\BillingEntity;
use App\Models\BillableMetric;
use App\Models\Integrations\NetsuiteIntegration;
use App\Models\IntegrationMappings\NetsuiteMapping;
use App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping;

/**
 * Ports of Rails' spec/graphql/mutations/integration_mappings/* and
 * spec/graphql/{mutations,resolvers}/integration_collection_mappings* —
 * the mapping CRUD surface over POST /graphql, against the frozen-schema
 * contract.
 */
function gqlMappingSetup(string $email): array
{
    $organization = gqlCreateOrganization('Mappings Org');
    BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser($email);
    gqlCreateMembership($user, $organization);

    $integration = NetsuiteIntegration::factory()->forOrganization($organization)->create();

    return [$organization->refresh(), $user, $integration];
}

it('creates an integration mapping', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('mapping-create@example.com');
    $addOn = AddOn::factory()->for($organization, 'organization')->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationMappingInput!) {
        createIntegrationMapping(input: $input) {
            id
            integrationId
            mappableId
            mappableType
            billingEntityId
            externalAccountCode
            externalId
            externalName
        }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappableId' => $addOn->id,
        'mappableType' => 'AddOn',
        'externalAccountCode' => 'code-1',
        'externalId' => 'external-1',
        'externalName' => 'Name 1',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createIntegrationMapping');

    expect($payload['id'])->not->toBeNull()
        ->and($payload['integrationId'])->toBe($integration->id)
        ->and($payload['mappableId'])->toBe($addOn->id)
        ->and($payload['mappableType'])->toBe('AddOn')
        ->and($payload['billingEntityId'])->toBeNull()
        ->and($payload['externalAccountCode'])->toBe('code-1')
        ->and($payload['externalId'])->toBe('external-1')
        ->and($payload['externalName'])->toBe('Name 1');

    expect(NetsuiteMapping::query()->count())->toBe(1);
});

it('creates an integration mapping with a billing entity', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('mapping-be@example.com');
    $addOn = AddOn::factory()->for($organization, 'organization')->create();
    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationMappingInput!) {
        createIntegrationMapping(input: $input) { id billingEntityId }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappableId' => $addOn->id,
        'mappableType' => 'AddOn',
        'externalId' => 'external-1',
        'billingEntityId' => $billingEntity->id,
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createIntegrationMapping.billingEntityId'))->toBe($billingEntity->id);
});

it('refuses a mapping whose billing entity belongs to another organization', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('mapping-foreign-be@example.com');
    $addOn = AddOn::factory()->for($organization, 'organization')->create();
    $foreignEntity = BillingEntity::factory()->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationMappingInput!) {
        createIntegrationMapping(input: $input) { id }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappableId' => $addOn->id,
        'mappableType' => 'AddOn',
        'externalId' => 'external-1',
        'billingEntityId' => $foreignEntity->id,
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createIntegrationMapping'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found');
});

it('refuses a mapping for an unknown integration', function (): void {
    [$organization, $user] = gqlMappingSetup('mapping-unknown@example.com');
    $addOn = AddOn::factory()->for($organization, 'organization')->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationMappingInput!) {
        createIntegrationMapping(input: $input) { id }
    }
    GQL, ['input' => [
        'integrationId' => '00000000-0000-0000-0000-000000000000',
        'mappableId' => $addOn->id,
        'mappableType' => 'AddOn',
        'externalId' => 'external-1',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
});

it('requires an authenticated user for the mapping mutations', function (): void {
    [$organization, , $integration] = gqlMappingSetup('mapping-auth@example.com');

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationMappingInput!) {
        createIntegrationMapping(input: $input) { id }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappableId' => '00000000-0000-0000-0000-000000000001',
        'mappableType' => 'AddOn',
        'externalId' => 'x',
    ]], []);

    expect($response->json('errors.0.extensions.status'))->toBe('unauthorized');
});

it('updates an integration mapping', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('mapping-update@example.com');
    $addOn = AddOn::factory()->for($organization, 'organization')->create();
    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateIntegrationMappingInput!) {
        updateIntegrationMapping(input: $input) {
            id
            externalId
            externalName
            externalAccountCode
        }
    }
    GQL, ['input' => [
        'id' => $mapping->id,
        'externalId' => '456',
        'externalName' => 'Name1',
        'externalAccountCode' => 'code-2',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateIntegrationMapping');

    expect($payload['id'])->toBe($mapping->id)
        ->and($payload['externalId'])->toBe('456')
        ->and($payload['externalName'])->toBe('Name1')
        ->and($payload['externalAccountCode'])->toBe('code-2');
});

it('destroys an integration mapping', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('mapping-destroy@example.com');
    $addOn = AddOn::factory()->for($organization, 'organization')->create();
    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: DestroyIntegrationMappingInput!) {
        destroyIntegrationMapping(input: $input) { id }
    }
    GQL, ['input' => ['id' => $mapping->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.destroyIntegrationMapping.id'))->toBe($mapping->id)
        ->and(NetsuiteMapping::query()->count())->toBe(0);
});

it('creates an integration collection mapping with tax settings', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-create@example.com');

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationCollectionMappingInput!) {
        createIntegrationCollectionMapping(input: $input) {
            id
            integrationId
            mappingType
            externalId
            taxNexus
            taxType
            taxCode
            billingEntityId
        }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappingType' => 'fallback_item',
        'externalId' => 'netsuite-123',
        'taxNexus' => '123',
        'taxCode' => '456',
        'taxType' => 'tax-type-1',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createIntegrationCollectionMapping');

    expect($payload['id'])->not->toBeNull()
        ->and($payload['integrationId'])->toBe($integration->id)
        ->and($payload['mappingType'])->toBe('fallback_item')
        ->and($payload['externalId'])->toBe('netsuite-123')
        ->and($payload['taxNexus'])->toBe('123')
        ->and($payload['taxType'])->toBe('tax-type-1')
        ->and($payload['taxCode'])->toBe('456');

    expect(NetsuiteCollectionMapping::query()->count())->toBe(1);
});

it('creates a currencies collection mapping from the input list', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-currencies@example.com');

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationCollectionMappingInput!) {
        createIntegrationCollectionMapping(input: $input) {
            id
            mappingType
            currencies { currencyCode currencyExternalCode }
        }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappingType' => 'currencies',
        'currencies' => [
            ['currencyCode' => 'EUR', 'currencyExternalCode' => '3'],
            ['currencyCode' => 'USD', 'currencyExternalCode' => '7'],
        ],
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.createIntegrationCollectionMapping');

    expect($payload['mappingType'])->toBe('currencies')
        ->and($payload['currencies'])->toBe([
            ['currencyCode' => 'EUR', 'currencyExternalCode' => '3'],
            ['currencyCode' => 'USD', 'currencyExternalCode' => '7'],
        ]);
});

it('refuses a currencies collection mapping with duplicated currency codes', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-dup@example.com');

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationCollectionMappingInput!) {
        createIntegrationCollectionMapping(input: $input) { id }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappingType' => 'currencies',
        'currencies' => [
            ['currencyCode' => 'USD', 'currencyExternalCode' => '4'],
            ['currencyCode' => 'USD', 'currencyExternalCode' => '5'],
        ],
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createIntegrationCollectionMapping'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('duplicated_field');
});

it('refuses a currencies collection mapping scoped to a billing entity', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-org-only@example.com');
    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();

    $response = gqlPost(<<<'GQL'
    mutation($input: CreateIntegrationCollectionMappingInput!) {
        createIntegrationCollectionMapping(input: $input) { id }
    }
    GQL, ['input' => [
        'integrationId' => $integration->id,
        'mappingType' => 'currencies',
        'billingEntityId' => $billingEntity->id,
        'currencies' => [
            ['currencyCode' => 'USD', 'currencyExternalCode' => '4'],
        ],
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.extensions.details.billingEntity'))->toBe(['value_must_be_blank']);
});

it('updates an integration collection mapping', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-update@example.com');
    $mapping = NetsuiteCollectionMapping::factory()->withMappingType('tax')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateIntegrationCollectionMappingInput!) {
        updateIntegrationCollectionMapping(input: $input) {
            id
            externalId
            externalName
            externalAccountCode
            taxNexus
            taxType
            taxCode
        }
    }
    GQL, ['input' => [
        'id' => $mapping->id,
        'externalId' => '456',
        'externalName' => 'Name1',
        'externalAccountCode' => 'code-2',
        'taxNexus' => 'updated-123',
        'taxCode' => 'updated-456',
        'taxType' => 'updated-tax-type-1',
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateIntegrationCollectionMapping');

    expect($payload['id'])->toBe($mapping->id)
        ->and($payload['externalId'])->toBe('456')
        ->and($payload['taxNexus'])->toBe('updated-123')
        ->and($payload['taxCode'])->toBe('updated-456')
        ->and($payload['taxType'])->toBe('updated-tax-type-1');
});

it('updates a currencies collection mapping', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-currencies-update@example.com');
    $mapping = NetsuiteCollectionMapping::factory()->currencies()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: UpdateIntegrationCollectionMappingInput!) {
        updateIntegrationCollectionMapping(input: $input) {
            id
            currencies { currencyCode currencyExternalCode }
        }
    }
    GQL, ['input' => [
        'id' => $mapping->id,
        'currencies' => [
            ['currencyCode' => 'USD', 'currencyExternalCode' => '799344'],
        ],
    ]], gqlAuthHeaders($user, $organization->id));

    $payload = $response->json('data.updateIntegrationCollectionMapping');

    expect($payload['currencies'])->toBe([
        ['currencyCode' => 'USD', 'currencyExternalCode' => '799344'],
    ]);
});

it('destroys an integration collection mapping', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-destroy@example.com');
    $mapping = NetsuiteCollectionMapping::factory()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    mutation($input: DestroyIntegrationCollectionMappingInput!) {
        destroyIntegrationCollectionMapping(input: $input) { id }
    }
    GQL, ['input' => ['id' => $mapping->id]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.destroyIntegrationCollectionMapping.id'))->toBe($mapping->id)
        ->and(NetsuiteCollectionMapping::query()->count())->toBe(0);
});

it('queries the integration collection mappings', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('collection-query@example.com');
    $first = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);
    $second = NetsuiteCollectionMapping::factory()->currencies()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);
    $otherOrganization = gqlCreateOrganization('Other Org');
    $otherIntegration = NetsuiteIntegration::factory()->forOrganization($otherOrganization)->create();
    NetsuiteCollectionMapping::factory()->forIntegration($otherIntegration)->create([
        'organization_id' => $otherOrganization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    query($integrationId: ID!) {
        integrationCollectionMappings(integrationId: $integrationId) {
            collection {
                id
                mappingType
                externalId
                taxCode
                currencies { currencyCode currencyExternalCode }
            }
            metadata { currentPage totalCount }
        }
    }
    GQL, ['integrationId' => $integration->id], gqlAuthHeaders($user, $organization->id));

    $result = $response->json('data.integrationCollectionMappings');

    expect(count($result['collection']))->toBe(2)
        ->and($result['metadata']['currentPage'])->toBe(1)
        ->and($result['metadata']['totalCount'])->toBe(2);

    $fallback = collect($result['collection'])->firstWhere('mappingType', 'fallback_item');

    expect($fallback['id'])->toBe($first->id)
        ->and($fallback['externalId'])->toBe('netsuite-123')
        ->and($fallback['taxCode'])->toBe('tax-code-1')
        ->and($fallback['currencies'])->toBeNull();

    $currencies = collect($result['collection'])->firstWhere('mappingType', 'currencies');

    expect($currencies['currencies'])->toBe([
        ['currencyCode' => 'EUR', 'currencyExternalCode' => '3'],
        ['currencyCode' => 'USD', 'currencyExternalCode' => '7'],
    ]);
});

it('exposes the integrationMappings field on the AddOn type', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('addon-mappings@example.com');
    $addOn = AddOn::factory()->for($organization, 'organization')->create();
    $otherAddOn = AddOn::factory()->for($organization, 'organization')->create();

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);
    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $otherAddOn)->create([
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    query($id: ID!, $integrationId: ID) {
        addOn(id: $id) {
            id
            integrationMappings(integrationId: $integrationId) {
                id
                mappableType
                externalId
            }
        }
    }
    GQL, ['id' => $addOn->id, 'integrationId' => $integration->id], gqlAuthHeaders($user, $organization->id));

    $mappings = $response->json('data.addOn.integrationMappings');

    expect($mappings)->toHaveCount(1)
        ->and($mappings[0]['id'])->toBe($mapping->id)
        ->and($mappings[0]['mappableType'])->toBe('AddOn')
        ->and($mappings[0]['externalId'])->toBe('netsuite-123');
});

it('exposes the integrationMappings field on the BillableMetric type', function (): void {
    [$organization, $user, $integration] = gqlMappingSetup('metric-mappings@example.com');
    $metric = BillableMetric::factory()->for($organization, 'organization')->create();

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $metric)->create([
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(<<<'GQL'
    query($id: ID!) {
        billableMetric(id: $id) {
            id
            integrationMappings { id mappableType externalId }
        }
    }
    GQL, ['id' => $metric->id], gqlAuthHeaders($user, $organization->id));

    $mappings = $response->json('data.billableMetric.integrationMappings');

    expect($mappings)->toHaveCount(1)
        ->and($mappings[0]['mappableType'])->toBe('BillableMetric')
        ->and($mappings[0]['externalId'])->toBe('netsuite-123');
});
