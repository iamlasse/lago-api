<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :plan factory.
 *
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'name' => $this->faker->company(),
            'invoice_display_name' => $this->faker->words(2, true),
            'code' => $this->faker->regexify('[A-Za-z0-9]{10}'),
            'description' => $this->faker->sentence(),
            'interval' => 'monthly',
            'pay_in_advance' => false,
            'amount_cents' => 100,
            'amount_currency' => 'EUR',
        ];
    }

    public function payInAdvance(): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_in_advance' => true,
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'yearly',
        ]);
    }

    public function semiannual(): static
    {
        return $this->state(fn (array $attributes) => [
            'interval' => 'semiannual',
        ]);
    }
}
