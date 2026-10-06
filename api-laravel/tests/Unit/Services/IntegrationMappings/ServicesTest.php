<?php

declare(strict_types=1);

use App\Models\AddOn;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\IntegrationMappings\NetsuiteMapping;
use App\Services\IntegrationMappings\CreateService;
use App\Services\IntegrationMappings\UpdateService;
use App\Services\IntegrationMappings\DestroyService;

/**
 * Ports of Rails' spec/services/integration_mappings/{create,update,
 * destroy}_service_spec.rb.
 */
function mappingServiceFixtures(): array
{
    $organization = Organization::factory()->create();
    $integration = \App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create();
    $addOn = AddOn::factory()->for($organization, 'organization')->create();

    return [$organization, $integration, $addOn];
}

it('creates an integration mapping through the integration STI factory', function (): void {
    [$organization, $integration, $addOn] = mappingServiceFixtures();

    $result = CreateService::call(args: [
        'mappable_type' => 'AddOn',
        'mappable_id' => $addOn->id,
        'integration_id' => $integration->id,
        'external_id' => 'external_123',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->integration_mapping)->toBeInstanceOf(NetsuiteMapping::class)
        ->and($result->integration_mapping->mappable_type)->toBe('AddOn')
        ->and($result->integration_mapping->mappable_id)->toBe($addOn->id)
        ->and($result->integration_mapping->integration_id)->toBe($integration->id)
        ->and($result->integration_mapping->organization_id)->toBe($organization->id)
        ->and($result->integration_mapping->external_id)->toBe('external_123');

    expect(NetsuiteMapping::query()->count())->toBe(1);
});

it('creates an integration mapping with a billing entity', function (): void {
    [$organization, $integration, $addOn] = mappingServiceFixtures();
    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();

    $result = CreateService::call(args: [
        'mappable_type' => 'AddOn',
        'mappable_id' => $addOn->id,
        'integration_id' => $integration->id,
        'billing_entity_id' => $billingEntity->id,
        'external_id' => 'external_123',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->integration_mapping->billing_entity_id)->toBe($billingEntity->id)
        ->and($result->integration_mapping->external_id)->toBe('external_123');
});

it('refuses a billing entity of another organization', function (): void {
    [$organization, $integration, $addOn] = mappingServiceFixtures();
    $foreignEntity = BillingEntity::factory()->create();

    $result = CreateService::call(args: [
        'mappable_type' => 'AddOn',
        'mappable_id' => $addOn->id,
        'integration_id' => $integration->id,
        'billing_entity_id' => $foreignEntity->id,
        'external_id' => 'external_123',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('billing_entity_not_found');
});

it('refuses an unknown integration', function (): void {
    $result = CreateService::call(args: [
        'mappable_type' => 'AddOn',
        'mappable_id' => '00000000-0000-0000-0000-000000000000',
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('integration_not_found');
});

it('refuses a duplicate mapping with a validation failure', function (): void {
    [$organization, $integration, $addOn] = mappingServiceFixtures();

    $args = [
        'mappable_type' => 'AddOn',
        'mappable_id' => $addOn->id,
        'integration_id' => $integration->id,
        'external_id' => 'external_123',
    ];

    CreateService::call(args: $args);

    $result = CreateService::call(args: $args);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['mappable_type' => ['value_already_exist']]);
});

it('updates the external settings of an integration mapping', function (): void {
    [$organization, $integration, $addOn] = mappingServiceFixtures();

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);

    $result = UpdateService::call(integration_mapping: $mapping, params: [
        'external_id' => '456',
        'external_name' => 'Name1',
        'external_account_code' => 'code-2',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->integration_mapping)->toBeInstanceOf(NetsuiteMapping::class);

    $mapping->refresh();

    expect($mapping->external_id)->toBe('456')
        ->and($mapping->external_name)->toBe('Name1')
        ->and($mapping->external_account_code)->toBe('code-2');
});

it('destroys an integration mapping', function (): void {
    [$organization, $integration, $addOn] = mappingServiceFixtures();

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);

    $result = DestroyService::call(integration_mapping: $mapping);

    expect($result->success())->toBeTrue()
        ->and($result->integration_mapping->id)->toBe($mapping->id)
        ->and(NetsuiteMapping::query()->count())->toBe(0);
});

it('refuses destroying a missing integration mapping', function (): void {
    $result = DestroyService::call(integration_mapping: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('integration_mapping_not_found');
});
