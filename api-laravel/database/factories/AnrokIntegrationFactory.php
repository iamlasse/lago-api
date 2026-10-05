<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\AnrokIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :anrok_integration factory (spec/factories/integrations.rb):
 * api_key + connection_id JSON-encoded in secrets.
 *
 * @extends Factory<AnrokIntegration>
 */
class AnrokIntegrationFactory extends Factory
{
    protected $model = AnrokIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::ANROK_TYPE,
            'code' => 'anrok',
            'name' => 'Anrok Integration',
            'secrets' => json_encode([
                'api_key' => $this->faker->uuid(),
                'connection_id' => $this->faker->uuid(),
            ]),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
