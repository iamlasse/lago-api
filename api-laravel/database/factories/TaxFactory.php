<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tax;
use Illuminate\Support\Str;
use App\Models\BillingEntity;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :tax factory (spec/factories/taxes.rb).
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
            // NOTE: usage of applied_to_organization is deprecated. Please,
            // use appliedToBillingEntity() instead.
            'applied_to_organization' => false,
            'auto_generated' => false,
        ];
    }

    /**
     * Rails trait :applied_to_billing_entity — creates the
     * BillingEntity::AppliedTax join row on the given billing entity (or the
     * organization's default one). There is no model for the
     * `billing_entities_taxes` join yet, so the row is inserted directly.
     */
    public function appliedToBillingEntity(?BillingEntity $billingEntity = null): static
    {
        return $this->afterCreating(function (Tax $tax) use ($billingEntity): void {
            $entity = $billingEntity ?? $tax->organization->defaultBillingEntity;

            DB::table('billing_entities_taxes')->insert([
                'id' => (string) Str::uuid(),
                'billing_entity_id' => $entity->id,
                'tax_id' => $tax->id,
                'organization_id' => $tax->organization_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** Rails: the deprecated `applied_to_organization: true` flag. */
    public function appliedToOrganization(): static
    {
        return $this->state(fn (array $attributes) => [
            'applied_to_organization' => true,
        ]);
    }
}
