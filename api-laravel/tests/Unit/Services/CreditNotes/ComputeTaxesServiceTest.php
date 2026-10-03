<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Tax;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\FeeAppliedTax;
use App\Models\CreditNoteItem;
use App\Models\InvoiceAppliedTax;
use App\Services\CreditNotes\ComputeTaxesService;

/**
 * Port of spec/services/credit_notes/compute_taxes_service_spec.rb.
 */
function creditNoteComputeSetup(array $overrides = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'version_number' => 3,
    ]);

    $feeAmountCents = $overrides['fee_amount_cents'] ?? 100;
    $feeCouponsAmountCents = $overrides['fee_coupons_amount_cents'] ?? 0;
    $feeTaxAmountCents = $overrides['fee_tax_amount_cents'] ?? 20;
    $itemAmountCents = $overrides['item_amount_cents'] ?? 50;
    $invoiceTaxCode = $overrides['invoice_tax_code'] ?? null;

    $taxes = $overrides['taxes'] ?? [Tax::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'vat',
        'rate' => 20,
    ])];

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => $feeAmountCents,
        'precise_amount_cents' => $feeAmountCents,
        'precise_coupons_amount_cents' => $feeCouponsAmountCents,
        'taxes_rate' => collect($taxes)->sum(fn (Tax $tax) => (float) $tax->rate),
    ]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'customer_id' => $customer->id,
        'taxes_amount_cents' => 0,
        'precise_taxes_amount_cents' => 0,
    ]);

    foreach ($taxes as $tax) {
        FeeAppliedTax::factory()->create([
            'fee_id' => $fee->id,
            'tax_id' => $tax->id,
            'organization_id' => $invoice->organization_id,
            'tax_code' => $tax->code,
            'tax_rate' => $tax->rate,
            'amount_cents' => $feeTaxAmountCents,
            'precise_amount_cents' => $feeTaxAmountCents,
        ]);
        InvoiceAppliedTax::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_id' => $tax->id,
            'organization_id' => $invoice->organization_id,
            'tax_code' => $invoiceTaxCode ?? $tax->code,
            'tax_rate' => $tax->rate,
            'amount_cents' => $feeTaxAmountCents,
            'fees_amount_cents' => $feeAmountCents,
        ]);
    }

    CreditNoteItem::factory()->create([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => $itemAmountCents,
        'precise_amount_cents' => $itemAmountCents,
    ]);

    $creditNote = $creditNote->refresh();
    $creditNote->setRelation('items', $creditNote->items()->get());

    return [$invoice, $creditNote];
}

it('sets the credit note taxes from the credited items', function (): void {
    [$invoice, $creditNote] = creditNoteComputeSetup();

    $result = ComputeTaxesService::call(creditNote: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($creditNote->taxes_amount_cents)->toBe(10)
        ->and((float) $creditNote->precise_taxes_amount_cents)->toBe(10.0)
        ->and($creditNote->taxes_rate)->toBe(20.0)
        ->and($creditNote->coupons_adjustment_amount_cents)->toBe(0)
        ->and($creditNote->appliedTaxes->map(
            fn ($tax) => [$tax->tax_code, (int) $tax->amount_cents],
        )->all())->toBe([['vat', 10]]);
})->group('ledger:svc:CreditNotes.ComputeTaxesService');

it('taxes the credited amount after its share of the coupon', function (): void {
    [$invoice, $creditNote] = creditNoteComputeSetup(['fee_coupons_amount_cents' => 20]);

    $result = ComputeTaxesService::call(creditNote: $creditNote);

    expect($result->success())->toBeTrue()
        ->and((float) $result->coupons_adjustment_amount_cents)->toBe(10.0)
        ->and((float) $creditNote->precise_coupons_adjustment_amount_cents)->toBe(10.0)
        ->and($creditNote->coupons_adjustment_amount_cents)->toBe(10)
        ->and($creditNote->taxes_amount_cents)->toBe(8);
})->group('ledger:svc:CreditNotes.ComputeTaxesService');

it('subtracts the rounding taken by earlier credit notes', function (): void {
    [$invoice, $creditNote] = creditNoteComputeSetup();

    CreditNote::factory()->forInvoice($invoice)->create([
        'customer_id' => $invoice->customer_id,
        'taxes_amount_cents' => 11,
        'precise_taxes_amount_cents' => '10.5',
    ]);

    $result = ComputeTaxesService::call(creditNote: $creditNote, adjustRounding: true);

    expect($result->success())->toBeTrue()
        ->and((float) $creditNote->precise_taxes_amount_cents)->toBe(9.5)
        ->and($creditNote->taxes_amount_cents)->toBe(10);
})->group('ledger:svc:CreditNotes.ComputeTaxesService');

it('splits the rounded tax total across the tax rows', function (): void {
    $organization = App\Models\Organization::factory()->create();

    [$invoice, $creditNote] = creditNoteComputeSetup([
        'fee_amount_cents' => 10,
        'item_amount_cents' => 10,
        'fee_tax_amount_cents' => 1,
        'taxes' => [
            Tax::factory()->create(['organization_id' => $organization->id, 'code' => 'state', 'rate' => 5]),
            Tax::factory()->create(['organization_id' => $organization->id, 'code' => 'city', 'rate' => 5]),
        ],
    ]);

    $result = ComputeTaxesService::call(creditNote: $creditNote);

    expect($result->success())->toBeTrue()
        ->and($creditNote->taxes_amount_cents)->toBe(1)
        ->and($creditNote->appliedTaxes->pluck('amount_cents')->all())->toEqualCanonicalizing([1, 0]);
})->group('ledger:svc:CreditNotes.ComputeTaxesService');

it('returns the apply taxes failure when a fee tax has no matching invoice tax', function (): void {
    [$invoice, $creditNote] = creditNoteComputeSetup(['invoice_tax_code' => 'other']);

    $result = ComputeTaxesService::call(creditNote: $creditNote);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toContain('invoice_applied_tax_not_found');
})->group('ledger:svc:CreditNotes.ComputeTaxesService');
