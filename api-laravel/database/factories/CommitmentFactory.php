<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Commitment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :commitment factory (minimum commitment).
 *
 * @extends Factory<Commitment>
 */
class CommitmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => PlanFactory::new(),
            'commitment_type' => Commitment::MINIMUM_COMMITMENT,
            'amount_cents' => 10000,
            'invoice_display_name' => $this->faker->words(2, true),
        ];
    }
}
