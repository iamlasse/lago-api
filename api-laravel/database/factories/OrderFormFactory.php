<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\OrderForm;
use App\Models\QuoteVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :order_form factory (spec/factories/order_forms.rb).
 *
 * @extends Factory<OrderForm>
 */
class OrderFormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'quote_version_id' => QuoteVersionFactory::new(),
            'status' => 'generated',
        ];
    }

    /** Rails trait :signed. */
    public function signed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'signed',
            'signed_at' => now(),
        ]);
    }

    /** Pin the customer (and its organization). */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }

    /** Rails trait :expired. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => 'expired',
            'voided_at' => now(),
            'void_reason' => 'expired',
        ]);
    }

    /** Rails trait :voided. */
    public function voided(): static
    {
        return $this->state(fn (): array => [
            'status' => 'voided',
            'voided_at' => now(),
            'void_reason' => 'manual',
        ]);
    }

    public function withExpiresAt(\Illuminate\Support\Carbon $expiresAt): static
    {
        return $this->state(fn (): array => [
            'expires_at' => $expiresAt,
        ]);
    }

    public function forQuoteVersion(QuoteVersion $quoteVersion): static
    {
        return $this->state(fn (): array => [
            'quote_version_id' => $quoteVersion->id,
            'organization_id' => $quoteVersion->organization_id,
        ]);
    }
}
