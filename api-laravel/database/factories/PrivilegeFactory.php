<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Privilege;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :privilege factory
 * (spec/factories/entitlement/privileges.rb) — attached to a feature,
 * string value_type by default.
 *
 * @extends Factory<Privilege>
 */
class PrivilegeFactory extends Factory
{
    protected $model = Privilege::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'entitlement_feature_id' => FeatureFactory::new(),
            'code' => $this->faker->regexify('[a-z0-9]{10}'),
            'name' => $this->faker->optional()->name(),
            'value_type' => 'string',
            'config' => '{}',
        ];
    }

    /** Keep organization_id consistent with the attached feature. */
    public function configure(): static
    {
        return $this->afterCreating(function (Privilege $privilege): void {
            $feature = $privilege->feature;

            if ($feature !== null && $privilege->organization_id !== $feature->organization_id) {
                $privilege->forceFill(['organization_id' => $feature->organization_id])->save();
            }
        });
    }

    /** Bind the privilege to an existing feature. */
    public function forFeature(Feature $feature): static
    {
        return $this->state(fn (array $attributes) => [
            'entitlement_feature_id' => $feature->id,
            'organization_id' => $feature->organization_id,
        ]);
    }

    /** Rails: integer value_type. */
    public function integer(): static
    {
        return $this->state(fn (array $attributes) => [
            'value_type' => 'integer',
        ]);
    }

    /** Rails: boolean value_type. */
    public function boolean(): static
    {
        return $this->state(fn (array $attributes) => [
            'value_type' => 'boolean',
        ]);
    }

    /**
     * Rails trait :select — value_type select with the given options.
     *
     * @param  list<string>  $options
     */
    public function select(array $options): static
    {
        return $this->state(fn (array $attributes) => [
            'value_type' => 'select',
            'config' => json_encode(['select_options' => $options]),
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
