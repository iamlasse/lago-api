<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\IntegrationMappings\AnrokMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :anrok_mapping factory (spec/factories/
 * integration_mappings.rb).
 *
 * @extends Factory<AnrokMapping>
 */
class AnrokMappingFactory extends Factory
{
    protected $model = AnrokMapping::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => AnrokIntegrationFactory::new(),
            'mappable_type' => 'AddOn',
            'mappable_id' => AddOnFactory::new(),
            'settings' => [
                'external_id' => 'anrok-123',
                'external_account_code' => 'anrok-code-1',
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
