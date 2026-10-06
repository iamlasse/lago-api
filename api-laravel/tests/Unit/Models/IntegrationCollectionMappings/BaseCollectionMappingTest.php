<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;
use App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping;

/**
 * Port of Rails' spec/models/integration_collection_mappings/
 * base_collection_mapping_spec.rb and netsuite_collection_mapping_spec.rb —
 * the mapping-type enum, the scoped uniqueness, the billing-entity checks
 * and the Netsuite currencies validations.
 */
function collectionMappingFixtures(): array
{
    $organization = Organization::factory()->create();
    $integration = App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create();

    return [$organization, $integration];
}

it('covers every Rails mapping type', function (): void {
    expect(BaseCollectionMapping::MAPPING_TYPES)->toBe([
        'fallback_item', 'coupon', 'subscription_fee', 'minimum_commitment',
        'tax', 'prepaid_credit', 'credit_note', 'account', 'currencies',
    ]);
});

it('validates the mapping type uniqueness in scope of integration, organization and billing entity', function (): void {
    [$organization, $integration] = collectionMappingFixtures();
    $otherIntegration = App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create(['code' => 'netsuite-2']);
    $otherBillingEntity = BillingEntity::factory()->forOrganization($organization)->create();

    // Without billing entity — siblings that must NOT collide.
    NetsuiteCollectionMapping::factory()->withMappingType('coupon')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($otherIntegration)->create([
        'organization_id' => $organization->id,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $otherBillingEntity->id,
    ]);

    $mapping = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
    ]);

    expect($mapping->validateAttributes())->toBe([]);

    // With billing entity — siblings that must NOT collide.
    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();
    $otherEntity = BillingEntity::factory()->forOrganization($organization)->create();

    NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => null,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $otherEntity->id,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('coupon')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);
    NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($otherIntegration)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    $entityMapping = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    expect($entityMapping->validateAttributes())->toBe([]);

    // The duplicates DO collide.
    $duplicate = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
    ]);

    expect($duplicate->validateAttributes()['mapping_type'])->toBe(['value_already_exist']);

    NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    $duplicateEntity = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    expect($duplicateEntity->validateAttributes()['mapping_type'])->toBe(['value_already_exist']);
});

it('validates the billing entity organization', function (): void {
    [$organization, $integration] = collectionMappingFixtures();

    $foreignEntity = BillingEntity::factory()->create();

    $mapping = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
        'billing_entity_id' => $foreignEntity->id,
    ]);

    expect($mapping->validateAttributes()['billing_entity'])->toBe(['value_is_invalid']);

    $mapping->billing_entity_id = null;
    $mapping->unsetRelation('billingEntity'); // the relation was cached.

    expect($mapping->validateAttributes())->toBe([]);
});

it('stores and reads the Netsuite settings accessors', function (): void {
    [$organization, $integration] = collectionMappingFixtures();

    $mapping = NetsuiteCollectionMapping::factory()->forIntegration($integration)->make([
        'organization_id' => $organization->id,
        'settings' => [],
    ]);

    $mapping->external_id = 'ext-1';
    $mapping->external_account_code = 'account-1';
    $mapping->external_name = 'Name 1';
    $mapping->tax_nexus = 'nexus-1';
    $mapping->tax_type = 'type-1';
    $mapping->tax_code = 'code-1';
    $mapping->currencies = ['EUR' => '8'];

    expect($mapping->external_id)->toBe('ext-1')
        ->and($mapping->external_account_code)->toBe('account-1')
        ->and($mapping->external_name)->toBe('Name 1')
        ->and($mapping->tax_nexus)->toBe('nexus-1')
        ->and($mapping->tax_type)->toBe('type-1')
        ->and($mapping->tax_code)->toBe('code-1')
        ->and($mapping->currencies)->toBe(['EUR' => '8']);
});

it('validates the Netsuite currencies mapping format', function (): void {
    [$organization, $integration] = collectionMappingFixtures();

    $make = fn () => NetsuiteCollectionMapping::factory()->withMappingType('currencies')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
    ]);

    // Blank currencies on a currencies mapping are rejected.
    $blank = $make();
    $blank->currencies = null;
    expect($blank->validateAttributes()['currencies'])->toBe(['value_is_mandatory']);

    $empty = $make();
    $empty->currencies = [];
    expect($empty->validateAttributes()['currencies'])->toBe(['cannot_be_empty']);

    // Invalid formats.
    foreach ([['USD' => 8], ['invalid' => '8'], ['USD' => '']] as $invalid) {
        $mapping = $make();
        $mapping->currencies = $invalid;
        expect($mapping->validateAttributes()['currencies'])->toBe(['invalid_format']);
    }

    // Valid currencies pass.
    $valid = $make();
    $valid->currencies = ['EUR' => '8', 'USD' => '3'];
    expect($valid->validateAttributes())->toBe([]);
});

it('rejects currencies on non-currencies mappings', function (): void {
    [$organization, $integration] = collectionMappingFixtures();

    $mapping = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
    ]);
    $mapping->currencies = ['EUR' => '12'];

    expect($mapping->validateAttributes()['currencies'])->toBe(['value_must_be_blank']);

    $mapping->currencies = null;

    expect($mapping->validateAttributes())->toBe([]);
});

it('restricts the currencies mapping to the organization level', function (): void {
    [$organization, $integration] = collectionMappingFixtures();
    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();

    $mapping = NetsuiteCollectionMapping::factory()->currencies()->forIntegration($integration)->make([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    expect($mapping->validateAttributes()['billing_entity'])->toContain('value_must_be_blank');

    $mapping->billing_entity_id = null;

    expect($mapping->validateAttributes())->toBe([]);

    // Other mapping types may carry a billing entity.
    $other = NetsuiteCollectionMapping::factory()->withMappingType('fallback_item')->forIntegration($integration)->make([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    expect($other->validateAttributes())->toBe([]);
});
