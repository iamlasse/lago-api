<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quote;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :quote factory (spec/factories/quotes.rb) — the ORDERS
 * slice reads quotes read-only (order_type + number); the full quotes
 * slice owns the real factory depth.
 *
 * @extends Factory<Quote>
 */
class QuoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'order_type' => 'subscription_creation',
        ];
    }

    /** Rails trait in the specs: an explicit order_type. */
    public function orderType(string $orderType): static
    {
        return $this->state(fn (): array => ['order_type' => $orderType]);
    }

    /** Pin the customer (and its organization) the quote belongs to. */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->id,
            'organization_id' => $customer->organization_id,
        ]);
    }
}
