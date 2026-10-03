<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Enums\InvoicePaymentStatus;
use App\Services\CreditNotes\AdjustAmountsWithRoundingService;

/**
 * Port of spec/services/credit_notes/adjust_amounts_with_rounding_service_spec.rb.
 */
function creditNoteAdjustSetup(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'total_amount_cents' => 25000,
        'taxes_amount_cents' => 5000,
        'fees_amount_cents' => 20000,
        'total_paid_amount_cents' => 25000,
        'taxes_rate' => 25,
        'payment_status' => InvoicePaymentStatus::Succeeded,
    ]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 20000,
        'taxes_rate' => 25,
    ]);

    return [$invoice, $customer, $fee];
}

it('adjusts the total and credit amount', function (): void {
    [$invoice, $customer, $fee] = creditNoteAdjustSetup();

    $creditNote = CreditNote::factory()->forInvoice($invoice)->make([
        'customer_id' => $customer->id,
        'taxes_amount_cents' => 4833,
        'credit_amount_cents' => 24167,
        'total_amount_cents' => 24167,
    ]);

    $item = CreditNoteItem::factory()->make([
        'fee_id' => $fee->id,
        'amount_cents' => 19333,
        'precise_amount_cents' => 19333,
        'amount_currency' => 'EUR',
    ]);
    $item->setRelation('fee', $fee);
    $creditNote->setRelation('items', collect([$item]));

    $result = AdjustAmountsWithRoundingService::call(creditNote: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->credit_note->taxes_amount_cents)->toBe(4833)
        ->and($result->credit_note->subTotalExcludingTaxesAmountCents())->toBe(19333)
        ->and($result->credit_note->credit_amount_cents)->toBe(24166)
        ->and($result->credit_note->total_amount_cents)->toBe(24166);
})->group('ledger:svc:CreditNotes.AdjustAmountsWithRoundingService');

it('adds a cent to the total when the rounding diff is negative', function (): void {
    [$invoice, $customer, $fee] = creditNoteAdjustSetup();

    $creditNote = CreditNote::factory()->forInvoice($invoice)->make([
        'customer_id' => $customer->id,
        'taxes_amount_cents' => 2,
        'credit_amount_cents' => 9,
        'total_amount_cents' => 9,
        'taxes_rate' => 20,
    ]);

    $item = CreditNoteItem::factory()->make([
        'fee_id' => $fee->id,
        'amount_cents' => 8,
        'precise_amount_cents' => '7.6',
        'amount_currency' => 'EUR',
    ]);
    $item->setRelation('fee', $fee);
    $creditNote->setRelation('items', collect([$item]));

    $result = AdjustAmountsWithRoundingService::call(creditNote: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($result->credit_note->items->first()->amount_cents)->toBe(8)
        ->and($result->credit_note->taxes_amount_cents)->toBe(2)
        ->and($result->credit_note->subTotalExcludingTaxesAmountCents())->toBe(8)
        ->and($result->credit_note->credit_amount_cents)->toBe(10)
        ->and($result->credit_note->total_amount_cents)->toBe(10);
})->group('ledger:svc:CreditNotes.AdjustAmountsWithRoundingService');
