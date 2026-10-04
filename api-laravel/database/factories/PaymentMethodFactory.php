<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :payment_method factory (the card_details variant).
 *
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
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
            'provider_method_id' => 'pm_'.$this->faker->regexify('[A-Za-z0-9]{24}'),
            'provider_method_type' => 'card',
            'is_default' => false,
            'details' => [
                'type' => 'card',
                'brand' => 'visa',
                'last4' => '4242',
                'expiration_month' => 12,
                'expiration_year' => (int) date('Y') + 3,
                'card_holder_name' => null,
                'issuer' => null,
            ],
        ];
    }

    /** Rails trait :payment_method_factory (default method). */
    public function asDefault(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    public function forProviderCustomer(PaymentProviderCustomer $providerCustomer): static
    {
        return $this->state(fn () => [
            'customer_id' => $providerCustomer->customer_id,
            'organization_id' => $providerCustomer->organization_id,
            'payment_provider_id' => $providerCustomer->payment_provider_id,
            'payment_provider_customer_id' => $providerCustomer->id,
        ]);
    }
}
