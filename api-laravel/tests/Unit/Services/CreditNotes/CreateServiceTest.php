<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Tax;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Models\CreditNote;
use App\Models\Organization;
use App\Models\FeeAppliedTax;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Models\InvoiceAppliedTax;
use App\Enums\InvoicePaymentStatus;
use App\Enums\CreditNoteCreditStatus;
use App\Enums\CreditNoteRefundStatus;
use App\Services\CreditNotes\CreateService;

/**
 * Port of spec/services/credit_notes/create_service_spec.rb (the scenarios
 * this slice ports — provider taxes, progressive billing and termination
 * creations live with the features that own them).
 */
function creditNotesCreateSetup(array $invoiceOverrides = []): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'fees_amount_cents' => 20,
        'total_amount_cents' => 24,
        'total_paid_amount_cents' => 6,
        'payment_status' => InvoicePaymentStatus::Succeeded,
        'taxes_rate' => 20,
        'version_number' => 2,
    ], $invoiceOverrides));

    return [$organization, $customer, $invoice];
}

function creditNotesCreateDefaultTaxes(Invoice $invoice, array $fees, float $rate = 20.0): Tax
{
    $tax = Tax::factory()->create([
        'organization_id' => $invoice->organization_id,
        'rate' => $rate,
    ]);

    foreach ($fees as $fee) {
        FeeAppliedTax::factory()->create([
            'fee_id' => $fee->id,
            'tax_id' => $tax->id,
            'organization_id' => $invoice->organization_id,
        ]);
    }

    InvoiceAppliedTax::factory()->create([
        'invoice_id' => $invoice->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
    ]);

    return $tax;
}

it('creates a credit note from an invoice', function (): void {
    [, , $invoice] = creditNotesCreateSetup();

    $fee1 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
        'taxes_amount_cents' => 1,
        'taxes_rate' => 20,
    ]);
    $fee2 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
        'taxes_amount_cents' => 1,
        'taxes_rate' => 20,
    ]);

    creditNotesCreateDefaultTaxes($invoice, [$fee1, $fee2]);

    $result = CreateService::call(
        invoice: $invoice,
        items: [
            ['fee_id' => $fee1->id, 'amount_cents' => 10],
            ['fee_id' => $fee2->id, 'amount_cents' => 5],
        ],
        description: null,
        creditAmountCents: 12,
        refundAmountCents: 6,
        automatic: true,
    );

    expect($result->success())->toBeTrue();

    $creditNote = $result->credit_note;

    expect($creditNote->invoice_id)->toBe($invoice->id)
        ->and($creditNote->customer_id)->toBe($invoice->customer_id)
        ->and($creditNote->issuing_date->toDateString())->toBe(now()->toDateString())

        ->and($creditNote->coupons_adjustment_amount_cents)->toBe(0)
        ->and($creditNote->taxes_amount_cents)->toBe(3)
        ->and($creditNote->taxes_rate)->toBe(20.0)
        ->and($creditNote->appliedTaxes()->count())->toBe(1)

        ->and($creditNote->total_amount_currency)->toBe('EUR')
        ->and($creditNote->total_amount_cents)->toBe(18)

        ->and($creditNote->credit_amount_cents)->toBe(12)
        ->and($creditNote->balance_amount_cents)->toBe(12)
        ->and($creditNote->creditStatusEnum())->toBe(CreditNoteCreditStatus::Available)

        ->and($creditNote->refund_amount_cents)->toBe(6)
        ->and($creditNote->refundStatusEnum())->toBe(CreditNoteRefundStatus::Pending)

        ->and($creditNote->reasonEnum())->toBe(CreditNoteReason::Other)

        ->and($creditNote->items()->count())->toBe(2)
        ->and($creditNote->items()->first()->fee_id)->toBe($fee1->id)
        ->and($creditNote->items()->first()->amount_cents)->toBe(10)
        ->and($creditNote->items()->first()->amount_currency)->toBe('EUR')
        ->and($creditNote->items()->orderBy('created_at')->get()->last()->fee_id)->toBe($fee2->id)
        ->and($creditNote->items()->orderBy('created_at')->get()->last()->amount_cents)->toBe(5);
})->group('ledger:svc:CreditNotes.CreateService');

it('creates a draft credit note from a draft invoice', function (): void {
    [, , $invoice] = creditNotesCreateSetup(['status' => App\Enums\InvoiceStatus::Draft]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
    ]);

    creditNotesCreateDefaultTaxes($invoice, [$fee]);

    $result = CreateService::call(
        invoice: $invoice,
        items: [['fee_id' => $fee->id, 'amount_cents' => 5]],
        creditAmountCents: 6,
        automatic: true,
    );

    expect($result->success())->toBeTrue()
        ->and($result->credit_note->statusEnum())->toBe(CreditNoteStatus::Draft);
})->group('ledger:svc:CreditNotes.CreateService');

it('fails with not_found when the invoice is missing', function (): void {
    $result = CreateService::call(invoice: null, items: [], automatic: true);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(App\Services\Failures\NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.CreateService');

it('is forbidden without a premium license', function (): void {
    // The test env has no LAGO_LICENSE — License.premium? resolves false.
    [, , $invoice] = creditNotesCreateSetup();

    $result = CreateService::call(invoice: $invoice, items: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(App\Services\Failures\ForbiddenFailure::class);
})->group('ledger:svc:CreditNotes.CreateService');

it('is not allowed below the credit notes invoice version', function (): void {
    [, , $invoice] = creditNotesCreateSetup(['version_number' => 1]);

    // Rails specs stub License.premium?; the port reads LAGO_LICENSE.
    config(['lago.license' => 'premium']);
    try {
        $result = CreateService::call(invoice: $invoice, items: []);

        expect($result->failure())->toBeTrue()
            ->and($result->getError())->toBeInstanceOf(App\Services\Failures\MethodNotAllowedFailure::class)
            ->and($result->getError()->code)->toBe('invalid_type_or_status');
    } finally {
        config(['lago.license' => null]);
    }
})->group('ledger:svc:CreditNotes.CreateService');

it('rejects non-offset amounts on unpaid prepaid credit invoices', function (): void {
    [, , $invoice] = creditNotesCreateSetup([
        'invoice_type' => InvoiceType::Credit,
        'payment_status' => InvoicePaymentStatus::Pending,
    ]);

    // Rails specs stub License.premium?; the port reads LAGO_LICENSE.
    config(['lago.license' => 'premium']);
    try {
        $result = CreateService::call(
            invoice: $invoice,
            items: [],
            creditAmountCents: 10,
        );

        expect($result->failure())->toBeTrue()
            ->and($result->getError())->toBeInstanceOf(App\Services\Failures\MethodNotAllowedFailure::class)
            ->and($result->getError()->code)->toBe('invalid_type_or_status');
    } finally {
        config(['lago.license' => null]);
    }
})->group('ledger:svc:CreditNotes.CreateService');

it('rejects a total that does not match the item amounts', function (): void {
    [, , $invoice] = creditNotesCreateSetup();

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
    ]);

    creditNotesCreateDefaultTaxes($invoice, [$fee]);

    $result = CreateService::call(
        invoice: $invoice,
        items: [['fee_id' => $fee->id, 'amount_cents' => 5]],
        creditAmountCents: 10,
        automatic: true,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['base' => ['does_not_match_item_amounts']]);
})->group('ledger:svc:CreditNotes.CreateService');

it('rejects an item amount higher than the remaining fee amount', function (): void {
    [, , $invoice] = creditNotesCreateSetup();

    $fee1 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
    ]);
    $fee2 = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
    ]);

    creditNotesCreateDefaultTaxes($invoice, [$fee1, $fee2]);

    $result = CreateService::call(
        invoice: $invoice,
        items: [
            ['fee_id' => $fee1->id, 'amount_cents' => 10],
            ['fee_id' => $fee2->id, 'amount_cents' => 15],
        ],
        creditAmountCents: 10,
        refundAmountCents: 15,
        automatic: true,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['amount_cents' => ['higher_than_remaining_fee_amount']]);
})->group('ledger:svc:CreditNotes.CreateService');

it('rejects a refund on an unpaid invoice', function (): void {
    [, , $invoice] = creditNotesCreateSetup(['total_paid_amount_cents' => 0]);

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
    ]);

    creditNotesCreateDefaultTaxes($invoice, [$fee]);

    $result = CreateService::call(
        invoice: $invoice,
        items: [['fee_id' => $fee->id, 'amount_cents' => 6]],
        creditAmountCents: 0,
        refundAmountCents: 6,
        automatic: true,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['refund_amount_cents' => ['cannot_refund_unpaid_invoice']]);
})->group('ledger:svc:CreditNotes.CreateService');

it('rejects items that are not an array', function (): void {
    [, , $invoice] = creditNotesCreateSetup();

    $result = CreateService::call(invoice: $invoice, items: 'nope', automatic: true);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages)->toBe(['items' => ['must_be_an_array']]);
})->group('ledger:svc:CreditNotes.CreateService');

it('fails with not_found when an item fee does not exist', function (): void {
    [, , $invoice] = creditNotesCreateSetup();

    $result = CreateService::call(
        invoice: $invoice,
        items: [['fee_id' => '00000000-0000-0000-0000-000000000000', 'amount_cents' => 5]],
        automatic: true,
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(App\Services\Failures\NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.CreateService');

it('does not persist anything in the preview context', function (): void {
    [, , $invoice] = creditNotesCreateSetup();

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 10,
    ]);

    creditNotesCreateDefaultTaxes($invoice, [$fee]);

    $result = CreateService::call(
        invoice: $invoice,
        items: [['fee_id' => $fee->id, 'amount_cents' => 10]],
        creditAmountCents: 12,
        automatic: true,
        context: 'preview',
    );

    expect($result->success())->toBeTrue()
        ->and($result->credit_note->exists)->toBeFalse()
        ->and($result->credit_note->total_amount_cents)->toBe(12)
        ->and(CreditNote::query()->where('invoice_id', $invoice->id)->count())->toBe(0);
})->group('ledger:svc:CreditNotes.CreateService');
