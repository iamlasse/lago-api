<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :add_on factory (the AddOns CRUD surface is a later
 * milestone — this factory supports the FixedCharges slice).
 *
 * @extends Factory<AddOn>
 */
class AddOnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'name' => $this->faker->name(),
            'invoice_display_name' => $this->faker->words(2, true),
            'code' => $this->faker->regexify('[A-Za-z0-9]{10}'),
            'description' => 'test description',
            'amount_cents' => 200,
            'amount_currency' => 'EUR',
        ];
    }
}
