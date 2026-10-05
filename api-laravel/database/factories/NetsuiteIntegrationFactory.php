<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\NetsuiteIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :netsuite_integration factory (spec/factories/
 * integrations.rb): the connection/secrets shape of a Netsuite TBA
 * integration.
 *
 * @extends Factory<NetsuiteIntegration>
 */
class NetsuiteIntegrationFactory extends Factory
{
    protected $model = NetsuiteIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::NETSUITE_TYPE,
            'code' => 'netsuite',
            'name' => 'Netsuite Integration',
            'settings' => [
                'client_id' => $this->faker->uuid(),
                'account_id' => $this->faker->uuid(),
                'script_endpoint_url' => $this->faker->url(),
                'token_id' => $this->faker->uuid(),
                'sync_credit_notes' => true,
                'sync_invoices' => true,
                'sync_payments' => true,
            ],
            'secrets' => json_encode([
                'connection_id' => $this->faker->uuid(),
                'client_secret' => $this->faker->uuid(),
                'token_secret' => $this->faker->uuid(),
            ]),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
