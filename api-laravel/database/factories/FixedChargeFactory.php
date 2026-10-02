<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\FixedCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :fixed_charge factory.
 *
 * @extends Factory<FixedCharge>
 */
class FixedChargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes) {
                $addOn = AddOn::query()->find($attributes['add_on_id'] ?? null);

                return $addOn?->organization_id
                    ?? OrganizationFactory::new();
            },
            'plan_id' => PlanFactory::new(),
            'add_on_id' => AddOnFactory::new(),
            'code' => $this->faker->regexify('[A-Za-z0-9]{10}'),
            'charge_model' => 'standard',
            'units' => 1,
            'properties' => ['amount' => (string) $this->faker->numberBetween(100, 500)],
            'invoice_display_name' => $this->faker->words(2, true),
        ];
    }

    public function payInAdvance(): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_in_advance' => true,
        ]);
    }

    public function graduated(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_model' => 'graduated',
            'properties' => [
                'graduated_ranges' => [
                    ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '5', 'flat_amount' => '200'],
                    ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '1', 'flat_amount' => '300'],
                ],
            ],
        ]);
    }

    public function volume(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_model' => 'volume',
            'properties' => [
                'volume_ranges' => [
                    ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '5', 'flat_amount' => '200'],
                    ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '1', 'flat_amount' => '300'],
                ],
            ],
        ]);
    }
}
