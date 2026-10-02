<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerTax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :customer_applied_tax factory (Customer::AppliedTax — the
 * customers_taxes join table).
 *
 * @extends Factory<CustomerTax>
 */
class CustomerTaxFactory extends Factory
{
    protected $model = CustomerTax::class;

    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'tax_id' => TaxFactory::new(),
            // Rails: `organization { customer&.organization || tax&.organization }`.
            'organization_id' => function (array $attributes): string {
                $customer = $attributes['customer_id'] instanceof Customer
                    ? $attributes['customer_id']
                    : Customer::query()->findOrFail((string) $attributes['customer_id']);

                return (string) $customer->organization_id;
            },
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->for($customer);
    }
}
