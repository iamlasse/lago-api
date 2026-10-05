<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use App\Models\IntegrationCustomers\AnrokCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :anrok_customer factory — a tax-kind integration customer
 * row tied to an Anrok integration.
 *
 * @extends Factory<AnrokCustomer>
 */
class AnrokCustomerFactory extends Factory
{
    protected $model = AnrokCustomer::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => AnrokIntegrationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'type' => IntegrationCustomer::ANROK_TYPE,
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
