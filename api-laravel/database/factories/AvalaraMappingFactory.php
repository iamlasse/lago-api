<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntegrationMappings\AvalaraMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :avalara_mapping factory (spec/factories/
 * integration_mappings.rb).
 *
 * @extends Factory<AvalaraMapping>
 */
class AvalaraMappingFactory extends Factory
{
    protected $model = AvalaraMapping::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => AvalaraIntegrationFactory::new(),
            'mappable_type' => 'AddOn',
            'mappable_id' => AddOnFactory::new(),
            'settings' => [
                'external_id' => 'avalara-123',
                'external_account_code' => 'avalara-code-1',
                'external_name' => 'Credits and Discounts',
            ],
        ];
    }

    public function forIntegration($integration): static
    {
        return $this->state(fn () => [
            'integration_id' => $integration->id,
            'organization_id' => $integration->organization_id,
        ]);
    }

    public function forMappable(string $mappableType, $mappable): static
    {
        return $this->state(fn () => [
            'mappable_type' => $mappableType,
            'mappable_id' => $mappable->id,
        ]);
    }
}
