<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fee;
use App\Models\CreditNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :credit_note_item factory (spec/factories/credit_note_items.rb).
 *
 * @extends Factory<\App\Models\CreditNoteItem>
 */
class CreditNoteItemFactory extends Factory
{
    protected $model = \App\Models\CreditNoteItem::class;

    public function definition(): array
    {
        return [
            'credit_note_id' => CreditNoteFactory::new(),
            'fee_id' => FeeFactory::new(),
            'organization_id' => function (array $attributes): string {
                $creditNote = CreditNote::query()->find($attributes['credit_note_id']);

                return $creditNote?->organization_id
                    ?? Fee::query()->find($attributes['fee_id'])?->organization_id
                    ?? OrganizationFactory::new()->create()->id;
            },
            'amount_cents' => 100,
            'precise_amount_cents' => 100,
            'amount_currency' => 'EUR',
        ];
    }
}
