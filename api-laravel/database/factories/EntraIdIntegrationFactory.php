<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\EntraIdIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :entra_id_integration factory (spec/factories/
 * integrations.rb): client_id/domain "foo.test"/tenant_id in settings,
 * client_secret JSON-encoded in secrets.
 *
 * @extends Factory<EntraIdIntegration>
 */
class EntraIdIntegrationFactory extends Factory
{
    protected $model = EntraIdIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::ENTRA_ID_TYPE,
            'code' => 'entra_id',
            'name' => 'Entra ID Integration',
            'settings' => [
                'client_id' => $this->faker->uuid(),
                'domain' => 'foo.test',
                'tenant_id' => $this->faker->uuid(),
            ],
            'secrets' => json_encode(['client_secret' => $this->faker->uuid()]),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
