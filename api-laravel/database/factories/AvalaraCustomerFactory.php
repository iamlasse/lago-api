<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\IntegrationCustomers\AvalaraCustomer;

/**
 * Port of Rails' :avalara_customer factory — a tax-kind integration customer
 * row tied to an Avalara integration; external_customer_id is the Avalara
 * contact id.
 *
 * @extends Factory<AvalaraCustomer>
 */
class AvalaraCustomerFactory extends Factory
{
    protected $model = AvalaraCustomer::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => AvalaraIntegrationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'type' => IntegrationCustomer::AVALARA_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['tax'],
            'external_customer_id' => $this->faker->uuid(),
            'settings' => ['sync_with_provider' => true],
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
