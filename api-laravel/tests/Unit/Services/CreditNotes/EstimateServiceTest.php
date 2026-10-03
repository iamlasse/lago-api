<?php

declare(strict_types=1);

use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ForbiddenFailure;
use App\Services\CreditNotes\EstimateService;
use App\Services\Failures\MethodNotAllowedFailure;

/**
 * Port of spec/services/credit_notes/estimate_service_spec.rb (the scenarios
 * this slice ports — credit (prepaid) invoices need the Wallets slice).
 */
function creditNoteEstimateSetup(array $invoiceOverrides = []): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $invoice = Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
        'fees_amount_cents' => 20,
        'total_amount_cents' => 24,
        'total_paid_amount_cents' => 24,
        'sub_total_excluding_taxes_amount_cents' => 20,
        'sub_total_including_taxes_amount_cents' => 24,
        'payment_status' => App\Enums\InvoicePaymentStatus::Succeeded,
        'taxes_amount_cents' => 4,
        'taxes_rate' => 20,
        'version_number' => 3,
    ], $invoiceOverrides));

    $fee = Fee::factory()->create([
        'invoice_id' => $invoice->id,
        'organization_id' => $invoice->organization_id,
        'amount_cents' => 20,
        'precise_amount_cents' => 20,
        'taxes_amount_cents' => 4,
        'taxes_rate' => 20,
    ]);

    $tax = App\Models\Tax::factory()->create([
        'organization_id' => $invoice->organization_id,
        'rate' => 20,
    ]);

    App\Models\FeeAppliedTax::factory()->create([
        'fee_id' => $fee->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
    ]);

    App\Models\InvoiceAppliedTax::factory()->create([
        'invoice_id' => $invoice->id,
        'tax_id' => $tax->id,
        'organization_id' => $invoice->organization_id,
    ]);

    return [$invoice, $customer, $fee];
}

it('estimates the credit note amounts', function (): void {
    putenv('LAGO_LICENSE=premium');
    try {
        [$invoice, , $fee] = creditNoteEstimateSetup();

        $result = EstimateService::call(
            invoice: $invoice,
            items: [['fee_id' => $fee->id, 'amount_cents' => 10]],
        );

        expect($result->success())->toBeTrue();

        $estimate = $result->credit_note;

        // Not persisted — the estimate is an in-memory credit note.
        expect($estimate->exists)->toBeFalse()
            ->and($estimate->taxes_amount_cents)->toBe(2)
            ->and((float) $estimate->precise_taxes_amount_cents)->toBe(2.0)
            // max_creditable = items 10 + taxes 2 - coupons 0
            ->and($estimate->credit_amount_cents)->toBe(12)
            // max_refundable = min(creditable 12, refundable 24)
            ->and($estimate->refund_amount_cents)->toBe(12)
            ->and($estimate->total_amount_cents)->toBe(12)
            // NOTE: Rails' estimate never sets balance_amount_cents either —
            // it stays unset on the in-memory credit note.
            ->and($estimate->balance_amount_cents)->toBeNull()
            ->and($estimate->currency())->toBe('EUR')
            ->and($estimate->appliedTaxes)->toHaveCount(1);
    } finally {
        putenv('LAGO_LICENSE=');
    }
})->group('ledger:svc:CreditNotes.EstimateService');

it('fails with not_found when the invoice is missing', function (): void {
    $result = EstimateService::call(invoice: null, items: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class);
})->group('ledger:svc:CreditNotes.EstimateService');

it('is forbidden without a premium license', function (): void {
    [$invoice] = creditNoteEstimateSetup();

    $result = EstimateService::call(invoice: $invoice, items: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ForbiddenFailure::class);
})->group('ledger:svc:CreditNotes.EstimateService');

it('is not allowed below the credit notes invoice version', function (): void {
    putenv('LAGO_LICENSE=premium');
    try {
        [$invoice] = creditNoteEstimateSetup(['version_number' => 1]);

        $result = EstimateService::call(invoice: $invoice, items: []);

        expect($result->failure())->toBeTrue()
            ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
            ->and($result->getError()->code)->toBe('invalid_type_or_status');
    } finally {
        putenv('LAGO_LICENSE=');
    }
})->group('ledger:svc:CreditNotes.EstimateService');

it('is not allowed on credit invoices while wallets are unported', function (): void {
    putenv('LAGO_LICENSE=premium');
    try {
        [$invoice] = creditNoteEstimateSetup(['invoice_type' => InvoiceType::Credit]);

        $result = EstimateService::call(invoice: $invoice, items: []);

        expect($result->failure())->toBeTrue()
            ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
            ->and($result->getError()->code)->toBe('invalid_type_or_status');
    } finally {
        putenv('LAGO_LICENSE=');
    }
})->group('ledger:svc:CreditNotes.EstimateService');

it('rejects items that are not an array', function (): void {
    putenv('LAGO_LICENSE=premium');
    try {
        [$invoice] = creditNoteEstimateSetup();

        $result = EstimateService::call(invoice: $invoice, items: 'nope');

        expect($result->failure())->toBeTrue()
            ->and($result->getError()->messages)->toBe(['items' => ['must_be_an_array']]);
    } finally {
        putenv('LAGO_LICENSE=');
    }
})->group('ledger:svc:CreditNotes.EstimateService');
