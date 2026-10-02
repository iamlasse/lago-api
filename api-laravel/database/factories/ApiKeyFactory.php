<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :api_key factory.
 *
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'API Key',
            // Rails binds the key to an organization created without its own
            // default API key.
            'organization_id' => OrganizationFactory::new()->withoutApiKey(),
        ];
    }

    /** Rails trait :expired. */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDays($this->faker->numberBetween(1, 1000)),
        ]);
    }

    /** Rails trait :expiring. */
    public function expiring(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addSeconds($this->faker->numberBetween(1, 10000000)),
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
