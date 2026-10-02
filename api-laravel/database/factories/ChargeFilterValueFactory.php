<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChargeFilterValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :charge_filter_value factory.
 *
 * @extends Factory<ChargeFilterValue>
 */
class ChargeFilterValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'charge_filter_id' => ChargeFilterFactory::new(),
            'billable_metric_filter_id' => BillableMetricFilterFactory::new(),
            'values' => [$this->faker->word()],
        ];
    }
}
