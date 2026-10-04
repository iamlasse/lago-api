<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Privilege;
use App\Models\Entitlement;
use App\Models\EntitlementValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :entitlement_value factory
 * (spec/factories/entitlement/entitlement_values.rb).
 *
 * @extends Factory<EntitlementValue>
 */
class EntitlementValueFactory extends Factory
{
    protected $model = EntitlementValue::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'entitlement_entitlement_id' => EntitlementFactory::new(),
            'entitlement_privilege_id' => PrivilegeFactory::new(),
            'value' => $this->faker->randomNumber(),
        ];
    }

    /** Keep organization_id consistent with the entitlement. */
    public function configure(): static
    {
        return $this->afterCreating(function (EntitlementValue $value): void {
            $entitlement = $value->entitlement;

            if ($entitlement !== null && $value->organization_id !== $entitlement->organization_id) {
                $value->forceFill(['organization_id' => $entitlement->organization_id])->save();
            }
        });
    }

    /** Bind to an existing entitlement + privilege pair. */
    public function forEntitlementAndPrivilege(Entitlement $entitlement, Privilege $privilege): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $entitlement->organization_id,
            'entitlement_entitlement_id' => $entitlement->id,
            'entitlement_privilege_id' => $privilege->id,
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
