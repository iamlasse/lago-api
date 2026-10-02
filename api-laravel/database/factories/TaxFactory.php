<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :tax factory.
 *
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'code' => 'vat-'.$this->faker->uuid(),
            'description' => 'French Standard VAT',
            'name' => 'VAT',
            'rate' => 20.0,
            'applied_to_organization' => false,
            'auto_generated' => false,
        ];
    }

    /**
     * Rails trait :applied_to_billing_entity — creates the billing entity
     * applied tax join row (needs BillingEntity::AppliedTax; TODO(port) —
     * only sets the deprecated applied_to_organization flag for now).
     */
    public function appliedToBillingEntity(): static
    {
        return $this->state(fn (array $attributes) => [
            'applied_to_organization' => false,
        ]);
    }

    public function appliedToOrganization(): static
    {
        return $this->state(fn (array $attributes) => [
            'applied_to_organization' => true,
        ]);
    }
}
