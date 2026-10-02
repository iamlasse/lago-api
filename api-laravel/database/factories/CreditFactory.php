<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Credit;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :credit factory.
 *
 * @extends Factory<Credit>
 */
class CreditFactory extends Factory
{
    protected $model = Credit::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'organization_id' => function (array $attributes): string {
                return Invoice::query()->find($attributes['invoice_id'])->organization_id;
            },
            'amount_cents' => $this->faker->randomNumber(3),
            'amount_currency' => 'EUR',
            'before_taxes' => true,
        ];
    }
}
