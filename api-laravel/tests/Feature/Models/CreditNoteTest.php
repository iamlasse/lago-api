<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use Illuminate\Support\Facades\DB;
use App\Enums\CreditNoteCreditStatus;

/**
 * CreditNote model conventions: numbering (Sequenced + ensure_number),
 * enum round-trips, voiding and the sub-total/rounding helpers.
 */
function creditNoteModelSetup(array $invoiceOverrides = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => App\Enums\InvoiceStatus::Finalized,
        'number' => 'LAGO-202610-001',
        'total_amount_cents' => 120,
        'taxes_amount_cents' => 20,
    ], $invoiceOverrides));

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 100,
        'precise_amount_cents' => 100,
    ]);

    return [$invoice, $customer, $fee];
}

it('generates its number from the invoice number and a per-invoice sequence', function (): void {
    [$invoice, $customer] = creditNoteModelSetup();

    $first = null;
    $second = null;

    DB::transaction(function () use ($invoice, $customer, &$first, &$second): void {
        $first = CreditNote::factory()->forInvoice($invoice)->create(['customer_id' => $customer->id]);
        $second = CreditNote::factory()->forInvoice($invoice)->create(['customer_id' => $customer->id]);
    });

    expect($first->number)->toBe($invoice->number.'-CN001')
        ->and($second->number)->toBe($invoice->number.'-CN002')
        ->and($first->sequential_id)->toBe(1)
        ->and($second->sequential_id)->toBe(2);
})->group('ledger:model:CreditNote');

it('sequences per invoice, not globally', function (): void {
    [$invoice1] = creditNoteModelSetup();
    [$invoice2] = creditNoteModelSetup();

    DB::transaction(function () use ($invoice1, $invoice2, &$a, &$b): void {
        $a = CreditNote::factory()->forInvoice($invoice1)->create();
        $b = CreditNote::factory()->forInvoice($invoice2)->create();
    });

    expect($a->sequential_id)->toBe(1)
        ->and($b->sequential_id)->toBe(1);
})->group('ledger:model:CreditNote');

it('round-trips the enums', function (): void {
    [$invoice] = creditNoteModelSetup();

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'status' => CreditNoteStatus::Draft,
        'reason' => CreditNoteReason::ProductUnsatisfactory,
        'credit_status' => CreditNoteCreditStatus::Consumed,
        'refund_status' => App\Enums\CreditNoteRefundStatus::Failed,
    ]);

    expect($creditNote->refresh()->statusEnum())->toBe(CreditNoteStatus::Draft)
        ->and($creditNote->reasonEnum())->toBe(CreditNoteReason::ProductUnsatisfactory)
        ->and($creditNote->creditStatusEnum())->toBe(CreditNoteCreditStatus::Consumed)
        ->and($creditNote->refundStatusEnum())->toBe(App\Enums\CreditNoteRefundStatus::Failed)
        ->and($creditNote->isDraft())->toBeTrue()
        ->and($creditNote->isFinalized())->toBeFalse();
})->group('ledger:model:CreditNote');

it('computes the sub-total and rounding helpers', function (): void {
    [$invoice] = creditNoteModelSetup();

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'credit_amount_cents' => 120,
        'total_amount_cents' => 144,
        'taxes_amount_cents' => 24,
        'precise_taxes_amount_cents' => '24.00000',
        'precise_coupons_adjustment_amount_cents' => '2.50000',
    ]);

    CreditNoteItem::factory()->create([
        'credit_note_id' => $creditNote->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 100,
        'precise_amount_cents' => '100.50000',
    ]);

    $creditNote = $creditNote->refresh();
    $creditNote->setRelation('items', $creditNote->items()->get());

    // 100.5 - 2.5 = 98
    expect($creditNote->subTotalExcludingTaxesAmountCents())->toBe(98)
        // 100.5 - 2.5 + 24 = 122
        ->and((float) $creditNote->preciseTotal())->toBe(122.0)
        // 100.5 - 2.5 + 24 = 122
        ->and($creditNote->subTotalIncludingTaxesAmountCents())->toBe(122)
        ->and((float) $creditNote->taxesRoundingAdjustment())->toBe(0.0)
        // 144 - 122 = 22
        ->and($creditNote->roundingAdjustment())->toBe(22);
})->group('ledger:model:CreditNote');

it('voids through mark_as_voided', function (): void {
    [$invoice] = creditNoteModelSetup();

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'balance_amount_cents' => 50,
    ]);

    expect($creditNote->voidable())->toBeTrue();

    $creditNote->markAsVoided();

    expect($creditNote->refresh()->isVoided())->toBeTrue()
        ->and($creditNote->voided_at)->not->toBeNull()
        ->and($creditNote->balance_amount_cents)->toBe(0)
        ->and($creditNote->voidable())->toBeFalse();
})->group('ledger:model:CreditNote');

it('exposes the rails conveniences', function (): void {
    [$invoice] = creditNoteModelSetup();

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'credit_amount_cents' => 120,
        'refund_amount_cents' => 0,
        'offset_amount_cents' => 0,
    ]);

    expect($creditNote->currency())->toBe('EUR')
        ->and($creditNote->credited())->toBeTrue()
        ->and($creditNote->refunded())->toBeFalse()
        ->and($creditNote->hasOffset())->toBeFalse()
        ->and($creditNote->purchaseOrderNumber())->toBe($invoice->purchase_order_number)
        ->and($creditNote->forCreditInvoice())->toBeFalse()
        ->and($creditNote->billingEntity->id)->toBe($invoice->billing_entity_id);
})->group('ledger:model:CreditNote');

it('lists the subscription ids of its fees', function (): void {
    [$invoice, $customer] = creditNoteModelSetup();

    $subscription = App\Models\Subscription::factory()->create(['customer_id' => $customer->id]);
    $fee1 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'subscription_id' => $subscription->id,
        'amount_cents' => 10,
    ]);
    $fee2 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'subscription_id' => $subscription->id,
        'amount_cents' => 10,
    ]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create();
    foreach ([$fee1, $fee2] as $fee) {
        CreditNoteItem::factory()->create([
            'credit_note_id' => $creditNote->id,
            'fee_id' => $fee->id,
            'organization_id' => $invoice->organization_id,
        ]);
    }

    expect($creditNote->subscriptionIds())->toBe([$subscription->id]);
})->group('ledger:model:CreditNote');
