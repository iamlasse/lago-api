<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :customer_metadata factory (Metadata::CustomerMetadata).
 *
 * @extends Factory<CustomerMetadata>
 */
class CustomerMetadataFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'organization_id' => function (array $attributes): string {
                $customer = $attributes['customer_id'] instanceof Customer
                    ? $attributes['customer_id']
                    : Customer::query()->findOrFail((string) $attributes['customer_id']);

                return (string) $customer->organization_id;
            },
            'key' => 'lead_name',
            'value' => 'John Doe',
            'display_in_invoice' => true,
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->for($customer);
    }
}
