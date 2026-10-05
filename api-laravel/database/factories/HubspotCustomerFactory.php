<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use App\Models\IntegrationCustomers\HubspotCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :hubspot_customer factory — a crm-kind integration
 * customer row tied to a Hubspot integration (the targeted object and the
 * synced contact email in settings).
 *
 * @extends Factory<HubspotCustomer>
 */
class HubspotCustomerFactory extends Factory
{
    protected $model = HubspotCustomer::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => HubspotIntegrationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'type' => IntegrationCustomer::HUBSPOT_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['crm'],
            'external_customer_id' => $this->faker->uuid(),
            'settings' => [
                'sync_with_provider' => true,
                'targeted_object' => 'contacts',
                'email' => $this->faker->safeEmail(),
            ],
        ];
    }

    public function forIntegration(Integration $integration): static
    {
        return $this->for($integration)->for($integration->organization, 'organization');
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->for($customer)->for($customer->organization, 'organization');
    }
}
