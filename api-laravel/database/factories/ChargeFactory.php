<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Charge;
use App\Models\BillableMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :charge factory and its per-charge-model factories
 * (standard / graduated / package / percentage / volume).
 *
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billable_metric_id' => BillableMetricFactory::new(),
            'plan_id' => PlanFactory::new(),
            'organization_id' => function (array $attributes) {
                $billableMetric = BillableMetric::query()->find($attributes['billable_metric_id']);

                return $billableMetric?->organization_id
                    ?? OrganizationFactory::new();
            },
            'code' => $this->faker->regexify('[A-Za-z0-9]{10}'),
            'invoice_display_name' => $this->faker->words(2, true),
            'charge_model' => 'standard',
            'properties' => ['amount' => (string) $this->faker->numberBetween(100, 500)],
        ];
    }

    public function standard(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_model' => 'standard',
            'properties' => ['amount' => (string) $this->faker->numberBetween(100, 500)],
        ]);
    }

    public function graduated(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_model' => 'graduated',
            'properties' => [
                'graduated_ranges' => [
                    ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '0', 'flat_amount' => '200'],
                    ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '300'],
                ],
            ],
        ]);
    }

    public function package(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_model' => 'package',
            'properties' => [
                'amount' => '100',
                'free_units' => 10,
                'package_size' => 10,
            ],
        ]);
    }

    public function percentage(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_model' => 'percentage',
            'properties' => [
                'rate' => '0.0555',
                'fixed_amount' => '2',
            ],
        ]);
    }

    public function volume(): static
    {
        return $this->state(fn (array $attributes) => [
            'charge_model' => 'volume',
            'properties' => [
                'volume_ranges' => [
                    ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '0', 'flat_amount' => '10'],
                    ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '0', 'flat_amount' => '5'],
                ],
            ],
        ]);
    }

    public function forPlan(Plan|int|string $plan): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => $plan instanceof Plan ? $plan->id : $plan,
            'organization_id' => $plan instanceof Plan ? $plan->organization_id : null,
        ]);
    }
}
