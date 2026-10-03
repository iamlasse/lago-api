<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Services\Invoices\RetryService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\MethodNotAllowedFailure;

uses()->group('ledger:svc:Invoices.RetryService');

/**
 * Port of Rails' spec/services/invoices/retry_service_spec.rb.
 */
it('returns a failure when the invoice is nil', function (): void {
    $result = RetryService::call(invoice: null);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(NotFoundFailure::class);
});

it('reopens a failed invoice as pending', function (): void {
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Failed,
        'tax_status' => 'succeeded',
    ]);

    $result = RetryService::call(invoice: $invoice);

    expect($result->success())->toBeTrue();
    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Pending);
    expect($invoice->taxPending())->toBeTrue();
});

it('returns invalid_status when the invoice is not failed', function (): void {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Finalized]);

    $result = RetryService::call(invoice: $invoice);

    expect($result->success())->toBeFalse();
    expect($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class);
    expect($result->getError()->code)->toBe('invalid_status');
    expect($invoice->refresh()->statusEnum())->toBe(InvoiceStatus::Finalized);
});
