<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tax;
use App\Models\CreditNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :credit_note_applied_tax factory
 * (spec/factories/credit_note_applied_taxes.rb).
 *
 * @extends Factory<\App\Models\CreditNoteAppliedTax>
 */
class CreditNoteAppliedTaxFactory extends Factory
{
    protected $model = \App\Models\CreditNoteAppliedTax::class;

    public function definition(): array
    {
        return [
            'credit_note_id' => CreditNoteFactory::new(),
            'tax_id' => TaxFactory::new(),
            'organization_id' => function (array $attributes): string {
                $creditNote = CreditNote::query()->find($attributes['credit_note_id']);

                return $creditNote?->organization_id
                    ?? Tax::query()->find($attributes['tax_id'])?->organization_id
                    ?? OrganizationFactory::new()->create()->id;
            },
            'tax_code' => 'vat-'.$this->faker->uuid(),
            'tax_description' => 'French Standard VAT',
            'tax_name' => 'VAT',
            'tax_rate' => 20.0,
            'amount_cents' => 200,
            'amount_currency' => 'EUR',
        ];
    }
}
