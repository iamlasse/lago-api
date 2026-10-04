<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\CreditNoteAppliedTax;
use App\Serializers\V1\CreditNoteSerializer;
use App\Serializers\V1\CreditNoteItemSerializer;
use App\Serializers\V1\CreditNotes\AppliedTaxSerializer;

/**
 * Ports of spec/serializers/v1/credit_note_serializer_spec.rb and
 * credit_note_item_serializer_spec.rb — the credit note shapes.
 */
function creditNoteSerializeSetup(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'number' => 'LAGO-202610-042',
        'currency' => 'EUR',
        'self_billed' => false,
    ]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 100,
    ]);

    $creditNote = CreditNote::factory()->forInvoice($invoice)->create([
        'customer_id' => $customer->id,
        'credit_amount_cents' => 120,
        'total_amount_cents' => 144,
        'taxes_amount_cents' => 24,
        'precise_taxes_amount_cents' => '24.00000',
        'precise_coupons_adjustment_amount_cents' => '2.50000',
        'coupons_adjustment_amount_cents' => 0,
        'balance_amount_cents' => 120,
        'taxes_rate' => 20.0,
        'description' => 'Oops',
        'status' => App\Enums\CreditNoteStatus::Finalized,
    ]);

    CreditNoteItem::factory()->create([
        'credit_note_id' => $creditNote->id,
        'fee_id' => $fee->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 100,
        'precise_amount_cents' => '100.50000',
    ]);

    CreditNoteAppliedTax::factory()->create([
        'credit_note_id' => $creditNote->id,
        'tax_id' => App\Models\Tax::factory()->create(['organization_id' => $organization->id, 'name' => 'VAT', 'code' => 'vat-20', 'rate' => 20.0])->id,
        'organization_id' => $invoice->organization_id,
        'tax_code' => 'vat-20',
        'tax_name' => 'VAT',
        'tax_rate' => 20.0,
        'tax_description' => 'Standard rate',
        'amount_cents' => 24,
        'base_amount_cents' => 120,
        'amount_currency' => 'EUR',
    ]);

    return [$organization, $creditNote, $fee];
}

it('serializes the credit note with literal snake_case keys', function (): void {
    [, $creditNote] = creditNoteSerializeSetup();
    $creditNote->setRelation('items', $creditNote->items()->get());

    $payload = (new CreditNoteSerializer($creditNote, ['includes' => ['items', 'applied_taxes']]))->serialize();

    expect($payload['lago_id'])->toBe($creditNote->id)
        ->and($payload['sequential_id'])->toBe($creditNote->sequential_id)
        ->and($payload['number'])->toBe($creditNote->number)
        ->and($payload['lago_invoice_id'])->toBe($creditNote->invoice_id)
        ->and($payload['invoice_number'])->toBe('LAGO-202610-042')
        ->and($payload['issuing_date'])->toBe($creditNote->issuing_date->format('Y-m-d'))
        ->and($payload['credit_status'])->toBe('available')
        ->and($payload['refund_status'])->toBeNull()
        ->and($payload['reason'])->toBe('duplicated_charge')
        ->and($payload['description'])->toBe('Oops')
        ->and($payload['currency'])->toBe('EUR')
        ->and($payload['total_amount_cents'])->toBe(144)
        ->and($payload['taxes_amount_cents'])->toBe(24)
        // Rails renders the BigDecimal with to_s("F").
        ->and($payload['precise_taxes_amount_cents'])->toBe('24.0')
        ->and($payload['sub_total_excluding_taxes_amount_cents'])->toBe(98)
        ->and($payload['balance_amount_cents'])->toBe(120)
        ->and($payload['credit_amount_cents'])->toBe(120)
        ->and($payload['coupons_adjustment_amount_cents'])->toBe(0)
        ->and($payload['taxes_rate'])->toBe(20.0)
        ->and($payload['self_billed'])->toBeFalse()
        ->and($payload['created_at'])->toBe($creditNote->created_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($payload['items'])->toHaveCount(1)
        ->and($payload['applied_taxes'])->toHaveCount(1)
        // file_url / xml_url stay null until ActiveStorage is ported.
        ->and($payload['file_url'])->toBeNull()
        ->and($payload['xml_url'])->toBeNull();
})->group('ledger:ser:V1.CreditNoteSerializer');

it('serializes the credit note items with their fee', function (): void {
    [, $creditNote, $fee] = creditNoteSerializeSetup();

    $item = $creditNote->items()->first();

    $payload = (new CreditNoteItemSerializer($item))->serialize();

    expect($payload['lago_id'])->toBe($item->id)
        ->and($payload['amount_cents'])->toBe(100)
        // Rails renders the BigDecimal with to_s("F").
        ->and($payload['precise_amount_cents'])->toBe('100.5')
        ->and($payload['amount_currency'])->toBe('EUR')
        ->and($payload['fee']['lago_id'])->toBe($fee->id);
})->group('ledger:ser:V1.CreditNoteItemSerializer');

it('serializes the credit note applied taxes', function (): void {
    [, $creditNote] = creditNoteSerializeSetup();

    $appliedTax = $creditNote->appliedTaxes()->first();

    $payload = (new AppliedTaxSerializer($appliedTax))->serialize();

    expect($payload['lago_id'])->toBe($appliedTax->id)
        ->and($payload['lago_credit_note_id'])->toBe($creditNote->id)
        ->and($payload['lago_tax_id'])->toBe($appliedTax->tax_id)
        ->and($payload['tax_name'])->toBe('VAT')
        ->and($payload['tax_code'])->toBe('vat-20')
        ->and($payload['tax_rate'])->toBe(20.0)
        ->and($payload['tax_description'])->toBe('Standard rate')
        ->and($payload['base_amount_cents'])->toBe(120)
        ->and($payload['amount_cents'])->toBe(24)
        ->and($payload['amount_currency'])->toBe('EUR')
        ->and($payload['created_at'])->toBe($appliedTax->created_at->utc()->format('Y-m-d\TH:i:s\Z'));
})->group('ledger:ser:V1.CreditNotes.AppliedTaxSerializer');
