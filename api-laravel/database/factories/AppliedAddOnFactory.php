<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\Customer;
use App\Models\AppliedAddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :applied_add_on factory
 * (spec/factories/applied_add_ons.rb).
 *
 * @extends Factory<AppliedAddOn>
 */
class AppliedAddOnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'add_on_id' => AddOnFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'amount_cents' => 200,
            'amount_currency' => 'EUR',
        ];
    }

    /** Rails: the applied add-on snapshots its add-on's amount. */
    public function forAddOn(AddOn $addOn, ?Customer $customer = null): static
    {
        return $this->state(fn (): array => [
            'add_on_id' => $addOn->id,
            'amount_cents' => $addOn->amount_cents,
            'amount_currency' => $addOn->amount_currency,
            ...($customer !== null ? ['customer_id' => $customer->id] : []),
        ]);
    }
}
