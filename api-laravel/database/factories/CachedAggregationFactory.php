<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Charge;
use App\Models\CachedAggregation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :cached_aggregation factory — the carried-over aggregation
 * value for recurring metrics (the M1 aggregation seam's input rows).
 *
 * @extends Factory<CachedAggregation>
 */
class CachedAggregationFactory extends Factory
{
    protected $model = CachedAggregation::class;

    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                return Charge::query()->find($attributes['charge_id'])->organization_id;
            },
            'charge_id' => ChargeFactory::new(),
            'external_subscription_id' => 'sub-'.$this->faker->uuid(),
            'timestamp' => now('UTC'),
            'current_aggregation' => '0',
            'grouped_by' => [],
            'presentation_breakdowns' => [],
        ];
    }
}
