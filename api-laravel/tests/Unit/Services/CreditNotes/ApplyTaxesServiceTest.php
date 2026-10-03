<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Tax;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\FeeAppliedTax;
use App\Models\CreditNoteItem;
use App\Models\InvoiceAppliedTax;
use App\Services\CreditNotes\ApplyTaxesService;

/**
 * Port of spec/services/credit_notes/apply_taxes_service_spec.rb (the local
 * tax scenarios — provider taxes live with the ProviderTaxes slice).
 */
function creditNoteApplySetup(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'fees_amount_cents' => 120,
        'coupons_amount_cents' => 20,
        'taxes_amount_cents' => 20,
        'total_amount_cents' => 120,
        'payment_status' => App\Enums\InvoicePaymentStatus::Succeeded,
        'taxes_rate' => 20,
        'version_number' => 3,
    ]);

    $fee1 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 100,
        'precise_amount_cents' => 100,
        'taxes_amount_cents' => 12,
        'taxes_rate' => 12,
        'precise_coupons_amount_cents' => 20 * 100 / 120,
    ]);

    $fee2 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 20,
        'precise_amount_cents' => 20,
        'taxes_amount_cents' => 4,
        'taxes_rate' => 20,
        'precise_coupons_amount_cents' => 20 * 20 / 120,
    ]);

    return [$invoice, $customer, $fee1, $fee2];
}

function creditNoteApplyItems(Invoice $invoice, Fee $fee1, Fee $fee2): array
{
    $build = fn (Fee $fee, int $amount) => tap(new CreditNoteItem([
        'fee_id' => $fee->id,
        'amount_cents' => $amount,
        'precise_amount_cents' => $amount,
        'amount_currency' => $invoice->currency,
    ]), fn ($item) => $item->setRelation('fee', $fee));

    return [$build($fee1, 20), $build($fee2, 50)];
}

it('creates applied taxes for local taxes', function (): void {
    [$invoice, $customer, $fee1, $fee2] = creditNoteApplySetup();
    $organization = $invoice->organization;

    $tax1 = Tax::factory()->create(['organization_id' => $organization->id, 'code' => 'tax1', 'rate' => 12]);
    $tax2 = Tax::factory()->create(['organization_id' => $organization->id, 'code' => 'tax2', 'rate' => 8]);

    foreach ([[$fee1, $tax1], [$fee2, $tax1], [$fee2, $tax2]] as [$fee, $tax]) {
        FeeAppliedTax::factory()->create([
            'fee_id' => $fee->id,
            'tax_id' => $tax->id,
            'organization_id' => $invoice->organization_id,
            'tax_code' => $tax->code,
            'tax_rate' => $tax->rate,
        ]);
    }

    foreach ([$tax1, $tax2] as $tax) {
        InvoiceAppliedTax::factory()->create([
            'invoice_id' => $invoice->id,
            'tax_id' => $tax->id,
            'organization_id' => $invoice->organization_id,
            'tax_code' => $tax->code,
            'tax_rate' => $tax->rate,
        ]);
    }

    $result = ApplyTaxesService::call(invoice: $invoice, items: creditNoteApplyItems($invoice, $fee1, $fee2));

    expect($result->success())->toBeTrue();

    $appliedTaxes = collect($result->applied_taxes)->sortBy('tax_code')->values();

    expect($appliedTaxes)->toHaveCount(2)
        ->and($appliedTaxes[0]->tax_code)->toBe('tax1')
        ->and($appliedTaxes[0]->tax_id)->toBe($tax1->id)
        ->and($appliedTaxes[0]->tax_rate)->toBe(12.0)
        ->and((int) $appliedTaxes[0]->amount_cents)->toBe(7)
        ->and($appliedTaxes[0]->amount_currency)->toBe('EUR')
        ->and($appliedTaxes[1]->tax_code)->toBe('tax2')
        ->and($appliedTaxes[1]->tax_rate)->toBe(8.0)
        ->and((int) $appliedTaxes[1]->amount_cents)->toBe(3)
        ->and((int) $result->taxes_amount_cents)->toBe(10)
        ->and((float) $result->taxes_rate)->toBe(17.71429)
        ->and((int) App\Support\MoneyMath::round((string) $result->coupons_adjustment_amount_cents))->toBe(12);
})->group('ledger:svc:CreditNotes.ApplyTaxesService');

it('builds a single credit note tax when two fee rates resolve to the same invoice tax', function (): void {
    [$invoice, $customer, $fee1, $fee2] = creditNoteApplySetup();
    $organization = $invoice->organization;

    // fee1 was taxed at an older rate than the one the invoice carries for the same code.
    $tax = Tax::factory()->create(['organization_id' => $organization->id, 'code' => 'vat', 'rate' => 12]);

    FeeAppliedTax::factory()->create([
        'fee_id' => $fee1->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
        'tax_code' => 'vat',
        'tax_rate' => 10,
    ]);
    FeeAppliedTax::factory()->create([
        'fee_id' => $fee2->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
        'tax_code' => 'vat',
        'tax_rate' => 12,
    ]);
    InvoiceAppliedTax::factory()->create([
        'invoice_id' => $invoice->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
        'tax_code' => 'vat',
        'tax_rate' => 12,
    ]);

    $result = ApplyTaxesService::call(invoice: $invoice, items: creditNoteApplyItems($invoice, $fee1, $fee2));

    expect($result->success())->toBeTrue()
        ->and(collect($result->applied_taxes)->map(
            fn ($t) => [$t->tax_code, (float) $t->tax_rate],
        )->all())->toBe([['vat', 12.0]])
        ->and((int) $result->applied_taxes[0]->base_amount_cents)->toBe(58)
        ->and((int) $result->applied_taxes[0]->amount_cents)->toBe(7)
        ->and((int) $result->taxes_amount_cents)->toBe(7);
})->group('ledger:svc:CreditNotes.ApplyTaxesService');
