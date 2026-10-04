<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :stripe_provider factory (spec/factories/payment_providers.rb
 * and the stripe variant) — a Stripe provider with a stored secret key.
 *
 * @extends Factory<PaymentProvider>
 */
class PaymentProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'type' => 'PaymentProviders::StripeProvider',
            'code' => 'stripe',
            'name' => 'Stripe',
            'secrets' => ['secret_key' => 'sk_test_'.$this->faker->regexify('[A-Za-z0-9]{24}')],
            'settings' => [],
        ];
    }

    /** Rails trait :stripe_provider (settings with webhook secret). */
    public function withWebhookSecret(): static
    {
        return $this->state(fn () => [
            'settings' => ['webhook_secret' => 'whsec_'.$this->faker->regexify('[A-Za-z0-9]{32}')],
        ]);
    }

    public function stripe(): static
    {
        return $this->state(fn () => [
            'type' => 'PaymentProviders::StripeProvider',
            'code' => 'stripe',
            'name' => 'Stripe',
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization, 'organization');
    }
}
