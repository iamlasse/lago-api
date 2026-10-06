<?php

declare(strict_types=1);

use App\Models\AddOn;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\BillableMetric;
use App\Models\IntegrationMappings\BaseMapping;
use App\Models\IntegrationMappings\NetsuiteMapping;

/**
 * Port of Rails' spec/models/integration_mappings/base_mapping_spec.rb —
 * the mappable-type inclusion, the scoped uniqueness, the billing-entity
 * organization check and the settings accessors.
 */
function mappingFixtures(): array
{
    $organization = Organization::factory()->create();
    $integration = App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create();
    $addOn = AddOn::factory()->for($organization, 'organization')->create();

    return [$organization, $integration, $addOn];
}

it('validates the mappable type inclusion', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
    ]);
    $mapping->mappable_type = 'Coupon';

    expect($mapping->validateAttributes())->toHaveKey('mappable_type');
});

it('accepts AddOn and BillableMetric mappable types', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();

    $billableMetric = BillableMetric::factory()->for($organization, 'organization')->create();

    $addOnMapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
    ]);
    $metricMapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $billableMetric)->make([
        'organization_id' => $organization->id,
    ]);

    expect($addOnMapping->validateAttributes())->toBe([])
        ->and($metricMapping->validateAttributes())->toBe([]);
});

it('rejects a duplicate mapping in scope of mappable, integration, organization and billing entity', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);

    $duplicate = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
    ]);

    expect($duplicate->validateAttributes()['mappable_type'])->toBe(['value_already_exist']);
});

it('accepts the same mappable under another integration, billing entity or mappable type', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();

    $otherIntegration = App\Models\Integrations\NetsuiteIntegration::factory()->forOrganization($organization)->create([
        'code' => 'netsuite-2',
    ]);
    $otherAddOn = AddOn::factory()->for($organization, 'organization')->create();
    $otherBillingEntity = BillingEntity::factory()->forOrganization($organization)->create();
    $otherOrganization = Organization::factory()->create();
    $billableMetric = BillableMetric::factory()->create();

    // Same shape as the Rails spec: siblings that must NOT collide.
    // (integrations has a unique (code, organization_id) index — the second
    // same-org integration gets its own code, like Rails' test data.)
    NetsuiteMapping::factory()->forIntegration($otherIntegration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);
    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $otherAddOn)->create([
        'organization_id' => $organization->id,
    ]);
    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $otherOrganization->id,
    ]);
    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('BillableMetric', $billableMetric)->create([
        'organization_id' => $organization->id,
    ]);
    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $otherBillingEntity->id,
    ]);

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
    ]);

    expect($mapping->validateAttributes())->toBe([]);
});

it('rejects a duplicate mapping with a billing entity in scope', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();
    $billingEntity = BillingEntity::factory()->forOrganization($organization)->create();

    NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    $duplicate = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
        'billing_entity_id' => $billingEntity->id,
    ]);

    expect($duplicate->validateAttributes()['mappable_type'])->toBe(['value_already_exist']);
});

it('validates the billing entity organization', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();
    $foreignEntity = BillingEntity::factory()->create();

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
        'billing_entity_id' => $foreignEntity->id,
    ]);

    expect($mapping->validateAttributes()['billing_entity'])
        ->toBe(['must belong to the same organization']);

    // Same organization (or no billing entity at all) is fine.
    $foreignEntity->organization_id = $organization->id;
    $foreignEntity->save();
    $mapping->unsetRelation('billingEntity'); // the relation was cached.

    expect($mapping->validateAttributes())->toBe([]);

    $mapping->billing_entity_id = null;

    expect($mapping->validateAttributes())->toBe([]);
});

it('pushes to and reads from settings', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
        'settings' => [],
    ]);

    $mapping->pushToSettings('key1', 'val1');

    expect($mapping->settings)->toBe(['key1' => 'val1'])
        ->and($mapping->getFromSettings('key1'))->toBe('val1')
        ->and($mapping->getFromSettings(null))->toBeNull()
        ->and($mapping->getFromSettings('foo'))->toBeNull();
});

it('exposes the external settings accessors as attributes', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();

    $mapping = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->make([
        'organization_id' => $organization->id,
        'settings' => [],
    ]);

    $mapping->external_id = 'external-1';
    $mapping->external_account_code = 'account-1';
    $mapping->external_name = 'Name 1';

    expect($mapping->external_id)->toBe('external-1')
        ->and($mapping->external_account_code)->toBe('account-1')
        ->and($mapping->external_name)->toBe('Name 1')
        ->and($mapping->settings)->toBe([
            'external_id' => 'external-1',
            'external_account_code' => 'account-1',
            'external_name' => 'Name 1',
        ]);
});

it('scopes subclass queries to the stored STI type', function (): void {
    [$organization, $integration, $addOn] = mappingFixtures();

    $created = NetsuiteMapping::factory()->forIntegration($integration)->forMappable('AddOn', $addOn)->create([
        'organization_id' => $organization->id,
    ]);

    expect($created->type)->toBe(BaseMapping::NETSUITE_TYPE)
        ->and(NetsuiteMapping::query()->whereKey($created->id)->exists())->toBeTrue()
        ->and(App\Models\IntegrationMappings\XeroMapping::query()->whereKey($created->id)->exists())->toBeFalse();
});
