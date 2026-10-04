<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PaymentProviderCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :stripe_customer factory (spec/factories/payment_provider_customers.rb)
 * — a Stripe connection row for a customer.
 *
 * @extends Factory<PaymentProviderCustomer>
 */
class PaymentProviderCustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => function (array $attributes): string {
                return Customer::query()->find($attributes['customer_id'] ?? null)->id
                    ?? CustomerFactory::new()->create([
                        'organization_id' => $attributes['organization_id'],
                    ])->id;
            },
            'type' => 'PaymentProviderCustomers::StripeCustomer',
            'provider_customer_id' => 'cus_'.$this->faker->regexify('[A-Za-z0-9]{14}'),
            'settings' => ['provider_payment_methods' => ['card']],
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }
}
