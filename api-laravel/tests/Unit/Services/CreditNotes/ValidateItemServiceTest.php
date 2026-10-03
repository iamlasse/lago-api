<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Models\CreditNoteItem;
use App\Services\Failures\NotFoundFailure;
use App\Services\CreditNotes\ValidateItemService;

/**
 * Port of spec/services/credit_notes/validate_item_service_spec.rb (the
 * scenarios this slice ports — wallet-bound credit invoices live with the
 * Wallets slice).
 */
function creditNoteItemValidateSetup(array $invoiceOverrides = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'total_amount_cents' => 120,
    ], $invoiceOverrides));

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 100,
        'taxes_rate' => 20,
    ]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'customer_id' => $customer->id,
        'credit_amount_cents' => 10,
    ]);

    return [$invoice, $customer, $fee, $creditNote];
}

function creditNoteItemValidate(CreditNote $creditNote, CreditNoteItem $item): BaseResult
{
    $result = BaseResult::of();
    (new ValidateItemService($result, item: $item))->valid();

    return $result;
}

it('validates the item', function (): void {
    [, , $fee, $creditNote] = creditNoteItemValidateSetup();

    $item = CreditNoteItem::factory()->make([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'amount_cents' => 10,
    ]);
    $item->setRelation('fee', $fee);
    $item->setRelation('creditNote', $creditNote);

    expect(creditNoteItemValidate($creditNote, $item)->success())->toBeTrue();
})->group('ledger:svc:CreditNotes.ValidateItemService');

it('fails with not_found when the fee is missing', function (): void {
    [, , , $creditNote] = creditNoteItemValidateSetup();

    $item = CreditNoteItem::factory()->make([
        'credit_note_id' => $creditNote->id,
        'fee_id' => null,
        'amount_cents' => 10,
    ]);
    $item->setRelation('fee', null);
    $item->setRelation('creditNote', $creditNote);

    $result = creditNoteItemValidate($creditNote, $item);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.ValidateItemService');

it('rejects a negative amount', function (): void {
    [, , $fee, $creditNote] = creditNoteItemValidateSetup();

    $item = CreditNoteItem::factory()->make([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'amount_cents' => -3,
    ]);
    $item->setRelation('fee', $fee);
    $item->setRelation('creditNote', $creditNote);

    $result = creditNoteItemValidate($creditNote, $item);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['amount_cents' => ['invalid_value']]);
})->group('ledger:svc:CreditNotes.ValidateItemService');

it('accepts a zero amount', function (): void {
    [, , $fee, $creditNote] = creditNoteItemValidateSetup();

    $item = CreditNoteItem::factory()->make([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'amount_cents' => 0,
    ]);
    $item->setRelation('fee', $fee);
    $item->setRelation('creditNote', $creditNote);

    expect(creditNoteItemValidate($creditNote, $item)->success())->toBeTrue();
})->group('ledger:svc:CreditNotes.ValidateItemService');

it('rejects an amount higher than the fee amount', function (): void {
    [, , $fee, $creditNote] = creditNoteItemValidateSetup();

    $item = CreditNoteItem::factory()->make([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'amount_cents' => 110,
    ]);
    $item->setRelation('fee', $fee);
    $item->setRelation('creditNote', $creditNote);

    $result = creditNoteItemValidate($creditNote, $item);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['amount_cents' => ['higher_than_remaining_fee_amount']]);
})->group('ledger:svc:CreditNotes.ValidateItemService');

it('takes previously credited amounts into account', function (): void {
    [, , $fee, $creditNote] = creditNoteItemValidateSetup();

    // 99 cents of the fee are already credited.
    CreditNoteItem::factory()->create([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'organization_id' => $fee->organization_id,
        'amount_cents' => 99,
    ]);

    $item = CreditNoteItem::factory()->make([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'amount_cents' => 2,
    ]);
    $item->setRelation('fee', $fee);
    $item->setRelation('creditNote', $creditNote);

    $result = creditNoteItemValidate($creditNote, $item);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['amount_cents' => ['higher_than_remaining_fee_amount']]);
})->group('ledger:svc:CreditNotes.ValidateItemService');
