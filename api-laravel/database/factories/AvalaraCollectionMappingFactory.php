<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntegrationCollectionMappings\BaseCollectionMapping;
use App\Models\IntegrationCollectionMappings\AvalaraCollectionMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :avalara_collection_mapping factory (spec/factories/
 * integration_collection_mappings.rb).
 *
 * @extends Factory<AvalaraCollectionMapping>
 */
class AvalaraCollectionMappingFactory extends Factory
{
    protected $model = AvalaraCollectionMapping::class;

    public function definition(): array
    {
        $mappingTypes = ['fallback_item', 'coupon', 'subscription_fee', 'minimum_commitment', 'tax', 'prepaid_credit', 'account'];

        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => AvalaraIntegrationFactory::new(),
            'mapping_type' => BaseCollectionMapping::mappingTypes()[$this->faker->randomElement($mappingTypes)],
            'settings' => [
                'external_id' => 'avalara-123',
                'external_account_code' => 'avalara-code-1',
                'external_name' => 'Credits and Discounts',
            ],
        ];
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
