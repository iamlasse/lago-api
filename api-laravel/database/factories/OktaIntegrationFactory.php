<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\OktaIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :okta_integration factory (spec/factories/integrations.rb):
 * client_id/domain "foo.test"/organization_name "Foobar" in settings,
 * client_secret JSON-encoded in secrets.
 *
 * @extends Factory<OktaIntegration>
 */
class OktaIntegrationFactory extends Factory
{
    protected $model = OktaIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::OKTA_TYPE,
            'code' => 'okta',
            'name' => 'Okta Integration',
            'settings' => [
                'client_id' => $this->faker->uuid(),
                'domain' => 'foo.test',
                'organization_name' => 'Foobar',
            ],
            'secrets' => json_encode(['client_secret' => $this->faker->uuid()]),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
