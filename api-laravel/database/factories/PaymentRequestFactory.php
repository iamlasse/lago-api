<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :payment_request factory.
 *
 * @extends Factory<PaymentRequest>
 */
class PaymentRequestFactory extends Factory
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
            'amount_cents' => 100,
            'amount_currency' => 'EUR',
            'email' => $this->faker->safeEmail(),
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['payment_status' => 1]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['payment_status' => 2]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }
}
