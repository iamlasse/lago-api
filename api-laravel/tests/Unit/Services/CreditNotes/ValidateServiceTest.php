<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Enums\InvoiceStatus;
use App\Services\BaseResult;
use App\Enums\CreditNoteStatus;
use App\Services\CreditNotes\ValidateService;

/**
 * Port of spec/services/credit_notes/validate_service_spec.rb (the scenarios
 * this slice ports).
 */
function creditNoteValidateSetup(array $invoiceOverrides = []): array
{
    $organization = \App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'fees_amount_cents' => 100,
        'total_amount_cents' => 120,
        'taxes_amount_cents' => 20,
        'total_paid_amount_cents' => 0,
    ], $invoiceOverrides));

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 100,
        'taxes_rate' => 20,
    ]);

    return [$invoice, $customer, $fee];
}

function creditNoteValidateBuild(Invoice $invoice, Customer $customer, Fee $fee, array $creditNoteOverrides = [], int $amountCents = 10): CreditNote
{
    $creditNote = CreditNote::factory()->forInvoice($invoice)->create(array_merge([
        'credit_amount_cents' => 12,
        'refund_amount_cents' => 0,
        'offset_amount_cents' => 0,
        'precise_coupons_adjustment_amount_cents' => 0,
        'precise_taxes_amount_cents' => 2,
        'total_amount_cents' => 12,
    ], $creditNoteOverrides));

    CreditNoteItem::factory()->create([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => $amountCents,
        'precise_amount_cents' => $amountCents,
        'amount_currency' => $invoice->currency,
    ]);

    return $creditNote;
}

function creditNoteValidate(CreditNote $creditNote): BaseResult
{
    $result = BaseResult::of();
    (new ValidateService($result, item: $creditNote))->valid();

    return $result;
}

it('validates the credit note', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup();
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee);

    expect(creditNoteValidate($creditNote)->success())->toBeTrue();
})->group('ledger:svc:CreditNotes.ValidateService');

it('rejects a refund when the invoice is not paid but fully accounted as paid', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup([
        'payment_status' => App\Enums\InvoicePaymentStatus::Pending,
        'total_paid_amount_cents' => 120,
    ]);
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee, [
        'refund_amount_cents' => 2,
        'total_amount_cents' => 14,
    ], amountCents: 12);

    $result = creditNoteValidate($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['refund_amount_cents' => ['cannot_refund_unpaid_invoice']]);
})->group('ledger:svc:CreditNotes.ValidateService');

it('accepts a zero refund on an unpaid invoice', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup([
        'payment_status' => App\Enums\InvoicePaymentStatus::Pending,
        'total_paid_amount_cents' => 6,
    ]);
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee);

    expect(creditNoteValidate($creditNote)->success())->toBeTrue();
})->group('ledger:svc:CreditNotes.ValidateService');

it('rejects a refund higher than the paid amount', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup([
        'payment_status' => App\Enums\InvoicePaymentStatus::Succeeded,
        'total_paid_amount_cents' => 120,
    ]);
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee, [
        'refund_amount_cents' => 121,
        'total_amount_cents' => 133,
    ], amountCents: 121);

    $result = creditNoteValidate($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toHaveKey('refund_amount_cents', ['higher_than_remaining_invoice_amount']);
})->group('ledger:svc:CreditNotes.ValidateService');

it('rejects a credit amount higher than the invoice amount', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup();
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee, [
        'credit_amount_cents' => 250,
        'total_amount_cents' => 250,
    ], amountCents: 250);

    $result = creditNoteValidate($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toHaveKey('credit_amount_cents', ['higher_than_remaining_invoice_amount']);
})->group('ledger:svc:CreditNotes.ValidateService');

it('rejects a total that does not match the item amounts', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup();
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee, amountCents: 1);

    $result = creditNoteValidate($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['does_not_match_item_amounts']]);
})->group('ledger:svc:CreditNotes.ValidateService');

it('rejects a zero total amount', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup();
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee, [
        'credit_amount_cents' => 0,
        'refund_amount_cents' => 0,
        'total_amount_cents' => 0,
        'precise_taxes_amount_cents' => 0,
        'precise_coupons_adjustment_amount_cents' => 0,
    ], amountCents: 0);

    $result = creditNoteValidate($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['total_amount_must_be_positive']]);
})->group('ledger:svc:CreditNotes.ValidateService');

it('accepts the last cent of the invoice total across two credit notes', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup([
        'fees_amount_cents' => 100,
        'coupons_amount_cents' => 10,
        'taxes_amount_cents' => 18,
        'total_amount_cents' => 108,
        'payment_status' => App\Enums\InvoicePaymentStatus::Succeeded,
        'taxes_rate' => 20,
        'version_number' => 3,
    ]);

    CreditNote::factory()->forInvoice($invoice)->create([
        'customer_id' => $customer->id,
        'credit_amount_cents' => 54,
        'total_amount_cents' => 54,
        'status' => CreditNoteStatus::Finalized,
    ]);

    // amount_cents 50 + precise_taxes 9 - coupons 5 = 54 — the second note
    // would bring the invoice total to 108 exactly.
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee, [
        'credit_amount_cents' => 54,
        'total_amount_cents' => 54,
        'precise_taxes_amount_cents' => 9,
        'precise_coupons_adjustment_amount_cents' => 5,
    ], amountCents: 50);

    expect(creditNoteValidate($creditNote)->success())->toBeTrue();
})->group('ledger:svc:CreditNotes.ValidateService');

it('rejects the amount above the taxed invoice total', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup([
        'fees_amount_cents' => 100,
        'coupons_amount_cents' => 10,
        'taxes_amount_cents' => 18,
        'total_amount_cents' => 108,
        'payment_status' => App\Enums\InvoicePaymentStatus::Succeeded,
        'taxes_rate' => 20,
        'version_number' => 3,
    ]);

    CreditNote::factory()->forInvoice($invoice)->create([
        'customer_id' => $customer->id,
        'credit_amount_cents' => 54,
        'total_amount_cents' => 54,
        'status' => CreditNoteStatus::Finalized,
    ]);

    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee, [
        'credit_amount_cents' => 66,
        'total_amount_cents' => 66,
        'precise_taxes_amount_cents' => 9,
        'precise_coupons_adjustment_amount_cents' => 5,
    ], amountCents: 62);

    $result = creditNoteValidate($creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toHaveKey('credit_amount_cents', ['higher_than_remaining_invoice_amount']);
})->group('ledger:svc:CreditNotes.ValidateService');

it('ignores the credit note itself in the remaining amount', function (): void {
    [$invoice, $customer, $fee] = creditNoteValidateSetup();
    $creditNote = creditNoteValidateBuild($invoice, $customer, $fee);

    // Same totals as its own — a second validation of the same note is
    // unaffected by its own credit amount.
    expect(creditNoteValidate($creditNote)->success())->toBeTrue();
})->group('ledger:svc:CreditNotes.ValidateService');
