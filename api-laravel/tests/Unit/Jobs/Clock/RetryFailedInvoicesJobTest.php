<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\ErrorDetail;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\RetryFailedInvoicesJob;
use App\Jobs\Invoices\ProviderTaxes\PullTaxesAndApplyJob;

uses()->group('ledger:job:Clock.RetryFailedInvoicesJob');

/**
 * Port of Rails' spec/jobs/clock/retry_failed_invoices_job_spec.rb — only
 * failed invoices whose error_details carry a provider-taxes "API limit"
 * message are retried (Rails asserts Invoices::RetryService got called;
 * here the observable effect is the invoice reopening to pending and the
 * provider-taxes pull job being enqueued).
 */
function retryFailedInvoiceSetup(string $taxErrorMessage): Invoice
{
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Failed,
        'created_at' => now()->subDays(2),
    ]);

    ErrorDetail::factory()->taxError()->forOwner($invoice)->forOrganization($invoice->organization)->create([
        'details' => [
            'tax_error' => 'validationError',
            'tax_error_message' => $taxErrorMessage,
        ],
    ]);

    return $invoice;
}

it('retries the failed invoice with an api limit error', function (): void {
    Queue::fake();

    $failedInvoice = retryFailedInvoiceSetup("You've exceeded your API limit of 10 per second");
    $finalizedInvoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Finalized,
        'created_at' => now()->subDays(2),
    ]);

    (new RetryFailedInvoicesJob)->handle();

    expect($failedInvoice->refresh()->statusEnum())->toBe(InvoiceStatus::Pending);
    expect($finalizedInvoice->refresh()->statusEnum())->toBe(InvoiceStatus::Finalized);

    Queue::assertPushed(PullTaxesAndApplyJob::class, fn (PullTaxesAndApplyJob $job): bool => $job->invoice->is($failedInvoice));
});

it('does not retry the failed invoice with an invalid product error', function (): void {
    Queue::fake();

    $failedInvoice = retryFailedInvoiceSetup('productExternalIdUnknown');

    (new RetryFailedInvoicesJob)->handle();

    expect($failedInvoice->refresh()->statusEnum())->toBe(InvoiceStatus::Failed);
    Queue::assertNotPushed(PullTaxesAndApplyJob::class);
});

it('does not retry invoices without error details', function (): void {
    Queue::fake();

    $failedInvoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Failed,
        'created_at' => now()->subDays(2),
    ]);

    (new RetryFailedInvoicesJob)->handle();

    expect($failedInvoice->refresh()->statusEnum())->toBe(InvoiceStatus::Failed);
    Queue::assertNotPushed(PullTaxesAndApplyJob::class);
});
