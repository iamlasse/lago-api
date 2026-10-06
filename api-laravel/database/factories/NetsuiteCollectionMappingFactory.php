<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;
use App\Models\IntegrationCollectionMappings\NetsuiteCollectionMapping;

/**
 * Port of Rails' :netsuite_collection_mapping / :netsuite_currencies_mapping
 * factories (spec/factories/integration_collection_mappings.rb).
 *
 * @extends Factory<NetsuiteCollectionMapping>
 */
class NetsuiteCollectionMappingFactory extends Factory
{
    protected $model = NetsuiteCollectionMapping::class;

    public function definition(): array
    {
        $mappingTypes = ['fallback_item', 'coupon', 'subscription_fee', 'minimum_commitment', 'tax', 'prepaid_credit'];

        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => NetsuiteIntegrationFactory::new(),
            'mapping_type' => BaseCollectionMapping::mappingTypes()[$this->faker->randomElement($mappingTypes)],
            'settings' => [
                'external_id' => 'netsuite-123',
                'external_account_code' => 'netsuite-code-1',
                'external_name' => 'Credits and Discounts',
                'tax_nexus' => 'tax-nexus-1',
                'tax_type' => 'tax-type-1',
                'tax_code' => 'tax-code-1',
            ],
        ];
    }

    /** Rails: the :netsuite_currencies_mapping factory. */
    public function currencies(): static
    {
        return $this->state(fn () => [
            'mapping_type' => BaseCollectionMapping::mappingTypes()['currencies'],
            'settings' => [
                'currencies' => [
                    'EUR' => '3',
                    'USD' => '7',
                ],
            ],
        ]);
    }

    public function withMappingType(string $mappingType): static
    {
        return $this->state(fn () => [
            'mapping_type' => BaseCollectionMapping::mappingTypes()[$mappingType],
        ]);
    }

    public function forIntegration($integration): static
    {
        return $this->state(fn () => [
            'integration_id' => $integration->id,
            'organization_id' => $integration->organization_id,
        ]);
    }
}
