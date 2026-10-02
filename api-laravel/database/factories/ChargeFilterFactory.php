<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Charge;
use App\Models\ChargeFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :charge_filter factory.
 *
 * @extends Factory<ChargeFilter>
 */
class ChargeFilterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'charge_id' => ChargeFactory::new(),
            'invoice_display_name' => $this->faker->words(2, true),
            'properties' => [],
        ];
    }

    public function forCharge(Charge|string $charge): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_id' => $charge instanceof Charge ? $charge->id : $charge,
            'organization_id' => $charge instanceof Charge ? $charge->organization_id : null,
        ]);
    }
}
