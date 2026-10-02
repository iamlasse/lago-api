<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillableMetricFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :billable_metric_filter factory.
 *
 * @extends Factory<BillableMetricFilter>
 */
class BillableMetricFilterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billable_metric_id' => BillableMetricFactory::new(),
            'key' => $this->faker->word(),
            'values' => [$this->faker->word(), $this->faker->word()],
        ];
    }

    /**
     * @param  list<string>  $values
     */
    public function withValues(string $key, array $values): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => $key,
            'values' => $values,
        ]);
    }
}
