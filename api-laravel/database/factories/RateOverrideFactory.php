<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RateOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :rate_override factory (spec/factories/rate_overrides.rb)
 * — a rate phase's own pricing.
 *
 * @extends Factory<RateOverride>
 */
class RateOverrideFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'rate_model' => 'standard',
            'rate_properties' => ['amount' => '200'],
            'min_amount_cents' => 0,
            'billing_interval_count' => null,
            'billing_interval_unit' => null,
        ];
    }

    /** Rails trait :graduated. */
    public function graduated(): static
    {
        return $this->state([
            'rate_model' => 'graduated',
            'rate_properties' => [
                'graduated_ranges' => [
                    ['from_value' => 0, 'to_value' => 10, 'per_unit_amount' => '1', 'flat_amount' => '5'],
                    ['from_value' => 11, 'to_value' => null, 'per_unit_amount' => '0.5', 'flat_amount' => '0'],
                ],
            ],
        ]);
    }
}
