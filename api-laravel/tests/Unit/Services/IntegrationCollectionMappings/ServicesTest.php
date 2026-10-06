<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;
use App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping;
use App\Services\IntegrationCollectionMappings\CreateService;
use App\Services\IntegrationCollectionMappings\UpdateService;
use App\Services\IntegrationCollectionMappings\DestroyService;

/**
 * Ports of Rails' spec/services/integration_collection_mappings/
 * {create,update,destroy}_service_spec.rb.
 */
function collectionServiceFixtures(): array
{
    $organization = Organization::factory()->create();
    $integration = \App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create();

    return [$organization, $integration];
}

it('creates an integration collection mapping', function (): void {
    [$organization, $integration] = collectionServiceFixtures();

    $result = CreateService::call(params: [
        'mapping_type' => 'fallback_item',
        'integration_id' => $integration->id,
        'organization_id' => $organization->id,
        'tax_nexus' => '123',
        'tax_code' => '456',
        'tax_type' => 'tax-type-1',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->integration_collection_mapping)->toBeInstanceOf(NetsuiteCollectionMapping::class)
        ->and($result->integration_collection_mapping->organization_id)->toBe($organization->id)
        ->and($result->integration_collection_mapping->mapping_type)
            ->toBe(BaseCollectionMapping::mappingTypes()['fallback_item'])
        ->and($result->integration_collection_mapping->integration_id)->toBe($integration->id)
        ->and($result->integration_collection_mapping->tax_nexus)->toBe('123')
        ->and($result->integration_collection_mapping->tax_code)->toBe('456')
        ->and($result->integration_collection_mapping->tax_type)->toBe('tax-type-1');

    expect(NetsuiteCollectionMapping::query()->count())->toBe(1);
});

it('creates an integration collection mapping with a billing entity', function (): void {
    [$organization, $integration] = collectionServiceFixtures();
    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();

    $result = CreateService::call(params: [
        'mapping_type' => 'fallback_item',
        'integration_id' => $integration->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->integration_collection_mapping->billing_entity_id)->toBe($billingEntity->id);
});

it('refuses a billing entity of another organization', function (): void {
    [$organization, $integration] = collectionServiceFixtures();
    $foreignEntity = BillingEntity::factory()->create();

    $result = CreateService::call(params: [
        'mapping_type' => 'fallback_item',
        'integration_id' => $integration->id,
        'billing_entity_id' => $foreignEntity->id,
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('billing_entity_not_found');
});

it('refuses a non-existent billing entity', function (): void {
    [$organization, $integration] = collectionServiceFixtures();

    $result = CreateService::call(params: [
        'mapping_type' => 'fallback_item',
        'integration_id' => $integration->id,
        'billing_entity_id' => 'non-existent-id',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('billing_entity_not_found');
});

it('refuses an unknown integration', function (): void {
    $result = CreateService::call(params: [
        'mapping_type' => 'fallback_item',
        'integration_id' => 'non-existent-id',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('integration_not_found');
});

it('validates the currencies format on create', function (): void {
    [$organization, $integration] = collectionServiceFixtures();

    $result = CreateService::call(params: [
        'mapping_type' => 'currencies',
        'integration_id' => $integration->id,
        'currencies' => ['yolo' => '1'],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['currencies'])->toBe(['invalid_format']);
});

it('updates an integration collection mapping', function (): void {
    [$organization, $integration] = collectionServiceFixtures();

    $mapping = NetsuiteCollectionMapping::factory()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $result = UpdateService::call(integration_collection_mapping: $mapping, params: [
        'external_id' => '456',
        'external_name' => 'Name1',
        'external_account_code' => 'code-2',
        'tax_nexus' => 'updated-123',
        'tax_code' => 'updated-456',
        'tax_type' => 'updated-tax-type-1',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->integration_collection_mapping)->toBeInstanceOf(NetsuiteCollectionMapping::class);

    $mapping->refresh();

    expect($mapping->external_id)->toBe('456')
        ->and($mapping->external_name)->toBe('Name1')
        ->and($mapping->external_account_code)->toBe('code-2')
        ->and($mapping->tax_nexus)->toBe('updated-123')
        ->and($mapping->tax_code)->toBe('updated-456')
        ->and($mapping->tax_type)->toBe('updated-tax-type-1');
});

it('updates a netsuite currencies mapping', function (): void {
    [$organization, $integration] = collectionServiceFixtures();

    $mapping = NetsuiteCollectionMapping::factory()->currencies()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $result = UpdateService::call(integration_collection_mapping: $mapping, params: [
        'currencies' => ['USD' => '799344'],
    ]);

    expect($result->success())->toBeTrue();

    $mapping->refresh();

    expect($mapping->currencies)->toBe(['USD' => '799344']);
});

it('validates the currencies format on update', function (): void {
    [$organization, $integration] = collectionServiceFixtures();

    $mapping = NetsuiteCollectionMapping::factory()->currencies()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $result = UpdateService::call(integration_collection_mapping: $mapping, params: [
        'currencies' => ['yolo' => true],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['currencies'])->toBe(['invalid_format']);
});

it('destroys an integration collection mapping', function (): void {
    [$organization, $integration] = collectionServiceFixtures();

    $mapping = NetsuiteCollectionMapping::factory()->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);

    $result = DestroyService::call(integration_collection_mapping: $mapping);

    expect($result->success())->toBeTrue()
        ->and($result->integration_collection_mapping->id)->toBe($mapping->id)
        ->and(NetsuiteCollectionMapping::query()->count())->toBe(0);
});

it('refuses destroying a missing integration collection mapping', function (): void {
    $result = DestroyService::call(integration_collection_mapping: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('integration_collection_mapping_not_found');
});
