<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Organization;
use App\Models\Integrations\HubspotIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :hubspot_integration factory (spec/factories/
 * integrations.rb): the targeted object in settings, the connection id
 * JSON-encoded in secrets.
 *
 * @extends Factory<HubspotIntegration>
 */
class HubspotIntegrationFactory extends Factory
{
    protected $model = HubspotIntegration::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => Integration::HUBSPOT_TYPE,
            'code' => 'hubspot',
            'name' => 'Hubspot Integration',
            'settings' => [
                'default_targeted_object' => 'contacts',
                'sync_invoices' => true,
                'sync_subscriptions' => true,
            ],
            'secrets' => json_encode(['connection_id' => $this->faker->uuid()]),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
