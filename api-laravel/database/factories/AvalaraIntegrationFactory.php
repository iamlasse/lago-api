<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\AvalaraIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :avalara_integration factory (spec/factories/integrations.rb):
 * account_id/company_code in settings, connection_id/license_key JSON-encoded
 * in secrets.
 *
 * @extends Factory<AvalaraIntegration>
 */
class AvalaraIntegrationFactory extends Factory
{
    protected $model = AvalaraIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::AVALARA_TYPE,
            'code' => 'avalara',
            'name' => 'Avalara Integration',
            'settings' => [
                'account_id' => $this->faker->uuid(),
                'company_code' => 'DEFAULT',
            ],
            'secrets' => json_encode([
                'connection_id' => $this->faker->uuid(),
                'license_key' => $this->faker->uuid(),
            ]),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
