<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillableMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :billable_metric factory (sum aggregation default).
 *
 * @extends Factory<BillableMetric>
 */
class BillableMetricFactory extends Factory
{
    // billable_metrics.aggregation_type integer enum: count=0, sum=1, max=2,
    // unique_count=3, (4 deleted), weighted_sum=5, latest=6, custom=7.
    public const SUM_AGG = 1;

    public const LATEST_AGG = 6;

    public const CUSTOM_AGG = 7;

    public const WEIGHTED_SUM_AGG = 5;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'name' => $this->faker->name(),
            'code' => $this->faker->regexify('[a-z0-9]{10}'),
            'description' => $this->faker->sentence(),
            'properties' => '{}',
            'aggregation_type' => self::SUM_AGG,
            'field_name' => 'amount',
            'recurring' => false,
        ];
    }

    public function latestAgg(): static
    {
        return $this->state(fn (array $attributes) => [
            'aggregation_type' => self::LATEST_AGG,
        ]);
    }

    public function customAgg(): static
    {
        return $this->state(fn (array $attributes) => [
            'aggregation_type' => self::CUSTOM_AGG,
            'custom_aggregator' => 'MAX(amount)',
        ]);
    }

    public function weightedSumAgg(): static
    {
        return $this->state(fn (array $attributes) => [
            'aggregation_type' => self::WEIGHTED_SUM_AGG,
            'field_name' => 'value',
            'weighted_interval' => 'seconds',
        ]);
    }

    /** Alias used by the billable-metrics slice's tests. */
    public function weightedSum(): static
    {
        return $this->weightedSumAgg();
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'recurring' => true,
        ]);
    }
}
