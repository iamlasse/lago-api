<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\CreditNoteCreditStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :credit_note factory (spec/factories/credit_notes.rb).
 *
 * @extends Factory<\App\Models\CreditNote>
 */
class CreditNoteFactory extends Factory
{
    protected $model = \App\Models\CreditNote::class;

    public function definition(): array
    {
        return [
            'organization_id' => OrganizationFactory::new(),
            'customer_id' => CustomerFactory::new(),
            'invoice_id' => function (array $attributes): string {
                return InvoiceFactory::new()->create([
                    'organization_id' => $attributes['organization_id'],
                    'customer_id' => $attributes['customer_id'],
                ])->id;
            },
            'issuing_date' => now('UTC')->toDateString(),
            'reason' => CreditNoteReason::DuplicatedCharge,
            'total_amount_cents' => 120,
            'total_amount_currency' => 'EUR',
            'taxes_amount_cents' => 20,
            'credit_status' => CreditNoteCreditStatus::Available,
            'credit_amount_cents' => 120,
            'credit_amount_currency' => 'EUR',
            'balance_amount_cents' => 120,
            'balance_amount_currency' => 'EUR',
            'status' => CreditNoteStatus::Finalized,
        ];
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn () => [
            'organization_id' => $invoice->organization_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => CreditNoteStatus::Draft]);
    }

    public function finalized(): static
    {
        return $this->state(fn () => ['status' => CreditNoteStatus::Finalized]);
    }

    public function deleted(): static
    {
        return $this->state(fn () => ['status' => CreditNoteStatus::Deleted]);
    }
}
