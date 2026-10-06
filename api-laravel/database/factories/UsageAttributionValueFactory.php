<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UsageAttributionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :usage_attribution_value factory.
 *
 * @extends Factory<\App\Models\UsageAttributionValue>
 */
class UsageAttributionValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'usage_attribution_type_id' => function (array $attributes): string {
                return UsageAttributionType::query()->find($attributes['usage_attribution_type_id'] ?? null)?->id
                    ?? UsageAttributionTypeFactory::new()->create([
                        'organization_id' => $attributes['organization_id'],
                    ])->id;
            },
            'customer_id' => function (array $attributes): string {
                return CustomerFactory::new()->create([
                    'organization_id' => $attributes['organization_id'],
                ])->id;
            },
            'value' => $this->faker->word(),
            'parent_id' => null,
        ];
    }
}
