<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Invoices\Payments\MarkOverdueJob;
use App\Jobs\Clock\MarkInvoicesAsPaymentOverdueJob;

uses()->group('ledger:job:Clock.MarkInvoicesAsPaymentOverdueJob');

/**
 * Port of Rails' spec/jobs/clock/mark_invoices_as_payment_overdue_job_spec.rb —
 * the clock fans out one Invoices::Payments::MarkOverdueJob per finalized,
 * unpaid invoice past its payment due date.
 */
function paymentOverdueSetup(array $overrides = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'status' => InvoiceStatus::Finalized,
        'payment_status' => InvoicePaymentStatus::Pending,
        'payment_overdue' => false,
        'payment_dispute_lost_at' => null,
        'payment_due_date' => now('UTC')->subDay()->toDateString(),
    ], $overrides));
}

it('enqueues mark-overdue jobs for past-due finalized invoices', function (): void {
    Queue::fake();

    $invoice = paymentOverdueSetup();

    (new MarkInvoicesAsPaymentOverdueJob)->handle();

    Queue::assertPushed(MarkOverdueJob::class, fn (MarkOverdueJob $job): bool => $job->invoice->is($invoice));
});

it('skips invoices already flagged payment_overdue', function (): void {
    Queue::fake();

    paymentOverdueSetup(['payment_overdue' => true]);

    (new MarkInvoicesAsPaymentOverdueJob)->handle();

    Queue::assertNotPushed(MarkOverdueJob::class);
});

it('skips invoices whose payment succeeded', function (): void {
    Queue::fake();

    paymentOverdueSetup(['payment_status' => InvoicePaymentStatus::Succeeded]);

    (new MarkInvoicesAsPaymentOverdueJob)->handle();

    Queue::assertNotPushed(MarkOverdueJob::class);
});

it('skips invoices with a lost payment dispute', function (): void {
    Queue::fake();

    paymentOverdueSetup(['payment_dispute_lost_at' => now('UTC')]);

    (new MarkInvoicesAsPaymentOverdueJob)->handle();

    Queue::assertNotPushed(MarkOverdueJob::class);
});

it('skips invoices whose due date is in the future', function (): void {
    Queue::fake();

    paymentOverdueSetup(['payment_due_date' => now('UTC')->addDays(30)->toDateString()]);

    (new MarkInvoicesAsPaymentOverdueJob)->handle();

    Queue::assertNotPushed(MarkOverdueJob::class);
});
