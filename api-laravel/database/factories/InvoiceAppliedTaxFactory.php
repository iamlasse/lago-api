<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tax;
use App\Models\Invoice;
use App\Models\InvoiceAppliedTax;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' invoices_taxes (Invoice::AppliedTax) factory.
 *
 * @extends Factory<InvoiceAppliedTax>
 */
class InvoiceAppliedTaxFactory extends Factory
{
    protected $model = InvoiceAppliedTax::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(),
            'tax_id' => TaxFactory::new(),
            'organization_id' => function (array $attributes): string {
                return Invoice::query()->find($attributes['invoice_id'])->organization_id;
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
            'fees_amount_cents' => 0,
            'amount_currency' => 'EUR',
        ];
    }
}
