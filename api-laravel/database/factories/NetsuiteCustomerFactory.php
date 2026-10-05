<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\IntegrationCustomers\NetsuiteCustomer;

/**
 * Port of Rails' :netsuite_customer factory — an accounting-kind integration
 * customer row tied to a Netsuite integration; external_customer_id is the
 * Netsuite customer id, subsidiary_id the customer's Netsuite subsidiary.
 *
 * @extends Factory<NetsuiteCustomer>
 */
class NetsuiteCustomerFactory extends Factory
{
    protected $model = NetsuiteCustomer::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => NetsuiteIntegrationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'type' => IntegrationCustomer::NETSUITE_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['accounting'],
            'external_customer_id' => $this->faker->uuid(),
            'settings' => [
                'sync_with_provider' => true,
                'subsidiary_id' => $this->faker->uuid(),
            ],
        ];
    }

    public function forIntegration(Integration $integration): static
    {
        return $this->state(fn () => [
            'integration_id' => $integration->id,
            'organization_id' => $integration->organization_id,
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }
}
