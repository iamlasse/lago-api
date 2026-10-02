<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\BillingEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :billing_entity factory.
 *
 * @extends Factory<BillingEntity>
 */
class BillingEntityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'code' => 'entity_'.$this->faker->uuid(),
            'default_currency' => 'USD',
            'email' => $this->faker->email(),
            'email_settings' => ['invoice.finalized', 'credit_note.created'],
            // Rails binds the entity to an organization created without its
            // own billing entities to avoid a double default entity.
            'organization_id' => OrganizationFactory::new()->withoutBillingEntity(),
        ];
    }

    /** Rails trait :archived. */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /** Rails trait :deleted. */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }

    /** Rails trait :with_static_values. */
    public function withStaticValues(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'ACME Corporation',
            'email' => 'billing@acme.com',
            'address_line1' => '123 Business St',
            'address_line2' => 'Suite 100',
            'city' => 'San Francisco',
            'state' => 'CA',
            'zipcode' => '94105',
            'country' => 'US',
            'document_number_prefix' => 'ACM-8924',
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization);
    }
}
