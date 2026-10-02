<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fee;
use App\Models\Tax;
use App\Models\FeeAppliedTax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :fee_applied_tax / fees_taxes factory.
 *
 * @extends Factory<FeeAppliedTax>
 */
class FeeAppliedTaxFactory extends Factory
{
    protected $model = FeeAppliedTax::class;

    public function definition(): array
    {
        return [
            'fee_id' => FeeFactory::new(),
            'tax_id' => TaxFactory::new(),
            'organization_id' => function (array $attributes): string {
                return Fee::query()->find($attributes['fee_id'])->organization_id;
            },
            'tax_description' => 'French Standard VAT',
            'tax_code' => function (array $attributes): string {
                return Tax::query()->find($attributes['tax_id'])->code;
            },
            'tax_name' => 'VAT',
            'tax_rate' => function (array $attributes): float {
                return (float) Tax::query()->find($attributes['tax_id'])->rate;
            },
            'amount_cents' => 0,
            'precise_amount_cents' => '0',
            'amount_currency' => 'EUR',
        ];
    }
}
