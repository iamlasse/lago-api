<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RateCard;
use App\Models\RateCardRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :rate_card_rate factory (spec/factories/rate_card_rates.rb).
 *
 * @extends Factory<RateCardRate>
 */
class RateCardRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'rate_card_id' => RateCardFactory::new(),
            'code' => 'rate_'.$this->faker->uuid(),
            'effective_from' => now()->startOfDay(),
            'rate_model' => 'standard',
            'rate_properties' => ['amount' => '100'],
            'min_amount_cents' => 0,
            'billing_interval_count' => 1,
            'billing_interval_unit' => 'month',
        ];
    }

    /** Rails: `for_rate_card`. */
    public function forRateCard(RateCard $rateCard): static
    {
        return $this->for($rateCard, 'rateCard');
    }

    /** Rails trait `:pending` — a rate effective in the future. */
    public function pending(string $effectiveFrom = '+1 month'): static
    {
        return $this->state(['effective_from' => now()->parse($effectiveFrom)->startOfDay()]);
    }

    /** Rails trait `:graduated`. */
    public function graduated(): static
    {
        return $this->state([
            'rate_model' => 'graduated',
            'rate_properties' => [
                'graduated_ranges' => [
                    ['from_value' => 0, 'to_value' => 100, 'per_unit_amount' => '0.5', 'flat_amount' => '10'],
                    ['from_value' => 101, 'to_value' => null, 'per_unit_amount' => '0.3', 'flat_amount' => '0'],
                ],
            ],
        ]);
    }
}
