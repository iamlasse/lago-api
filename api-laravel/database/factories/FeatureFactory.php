<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :feature / :privilege factories (spec/factories/
 * entitlement/features.rb).
 *
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    protected $model = Feature::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'code' => $this->faker->regexify('[a-z0-9]{10}'),
            'name' => $this->faker->name(),
            'description' => $this->faker->sentence(),
        ];
    }

    /** Rails trait :with_privileges — creates attached privileges. */
    public function withPrivileges(array $privilegeCodes): static
    {
        return $this->afterCreating(function (Feature $feature) use ($privilegeCodes): void {
            foreach ($privilegeCodes as $privilegeCode) {
                PrivilegeFactory::new()->create([
                    'organization_id' => $feature->organization_id,
                    'entitlement_feature_id' => $feature->id,
                    'code' => $privilegeCode,
                ]);
            }
        });
    }

    /** Bind the feature to an existing organization. */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }

    /** Rails trait :discarded. */
    public function discarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
