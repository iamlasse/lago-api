<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UsageAttributionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :usage_attribution_type factory.
 *
 * @extends Factory<UsageAttributionType>
 */
class UsageAttributionTypeFactory extends Factory
{
    protected $model = UsageAttributionType::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'code' => 'team_'.$this->faker->unique()->regexify('[a-z0-9]{8}'),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'role' => 0, // hierarchical
            'attribution_keys' => ['team'],
        ];
    }

    public function flat(): static
    {
        return $this->state(fn (): array => ['role' => 1]);
    }
}
