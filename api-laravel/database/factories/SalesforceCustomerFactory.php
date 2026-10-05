<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Integration;
use App\Models\IntegrationCustomer;
use App\Models\IntegrationCustomers\SalesforceCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :salesforce_customer factory — a crm-kind integration
 * customer row tied to a Salesforce integration.
 *
 * @extends Factory<SalesforceCustomer>
 */
class SalesforceCustomerFactory extends Factory
{
    protected $model = SalesforceCustomer::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'integration_id' => SalesforceIntegrationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'type' => IntegrationCustomer::SALESFORCE_TYPE,
            'category' => IntegrationCustomer::CATEGORIES['crm'],
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
