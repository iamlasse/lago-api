<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :customer factory — the customer attaches to the
 * organization's default billing entity (creating the organization when
 * none is given).
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'billing_entity_id' => function (array $attributes): string {
                $organizationId = $attributes['organization_id'] instanceof Organization
                    ? $attributes['organization_id']->id
                    : (string) $attributes['organization_id'];

                $billingEntity = BillingEntity::query()
                    ->where('organization_id', $organizationId)
                    ->whereNull('archived_at')
                    ->oldest('created_at')
                    ->first();

                if ($billingEntity === null) {
                    $billingEntity = BillingEntity::factory()->create([
                        'organization_id' => $organizationId,
                    ]);
                }

                return $billingEntity->id;
            },
            'name' => $this->faker->randomElement([
                'Richard Hendricks', 'Dinesh Chugtai', 'Bertram Gilfoyle', 'Jared Dunn',
                'Erlich Bachman', 'Monica Hall', 'Gavin Belson', 'Big Head',
            ]),
            'firstname' => $this->faker->firstName(),
            'lastname' => $this->faker->lastName(),
            'external_id' => $this->faker->uuid(),
            'country' => $this->faker->countryCode(),
            'address_line1' => $this->faker->streetAddress(),
            'address_line2' => $this->faker->secondaryAddress(),
            'state' => $this->faker->state(),
            'zipcode' => $this->faker->postcode(),
            'email' => $this->faker->email(),
            'city' => $this->faker->city(),
            'url' => $this->faker->url(),
            'phone' => $this->faker->phoneNumber(),
            'logo_url' => $this->faker->url(),
            'legal_name' => $this->faker->company(),
            'legal_number' => $this->faker->numerify('#########'),
            'currency' => 'EUR',
        ];
    }

    /** Rails trait :with_shipping_address. */
    public function withShippingAddress(): static
    {
        return $this->state(fn (array $attributes) => [
            'shipping_address_line1' => $this->faker->streetAddress(),
            'shipping_address_line2' => $this->faker->secondaryAddress(),
            'shipping_city' => $this->faker->city(),
            'shipping_zipcode' => $this->faker->postcode(),
            'shipping_state' => $this->faker->state(),
            'shipping_country' => $this->faker->countryCode(),
        ]);
    }

    /** Rails trait :with_same_billing_and_shipping_address. */
    public function withSameBillingAndShippingAddress(): static
    {
        return $this->state(fn (array $attributes) => [
            'shipping_address_line1' => $attributes['address_line1'],
            'shipping_address_line2' => $attributes['address_line2'],
            'shipping_city' => $attributes['city'],
            'shipping_zipcode' => $attributes['zipcode'],
            'shipping_state' => $attributes['state'],
            'shipping_country' => $attributes['country'],
        ]);
    }

    /** Rails trait :with_static_values. */
    public function withStaticValues(): static
    {
        return $this->state(fn (array $attributes) => [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'name' => 'John Doe',
            'legal_name' => 'Doe Corp',
            'legal_number' => '1234567890',
            'external_id' => 'customer_123',
            'email' => 'john.doe@example.com',
            'address_line1' => '456 Customer Ave',
            'address_line2' => 'Apt 202',
            'city' => 'New York',
            'state' => 'NY',
            'zipcode' => '10001',
            'country' => 'US',
            'phone' => '+1-555-123-4567',
        ])->withSameBillingAndShippingAddress();
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->for($organization, 'organization');
    }
}
