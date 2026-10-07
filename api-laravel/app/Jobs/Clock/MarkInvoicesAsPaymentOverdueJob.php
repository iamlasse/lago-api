<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use Illuminate\Bus\Queueable;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Invoices\Payments\MarkOverdueJob;

/**
 * Port of Rails' Clock::MarkInvoicesAsPaymentOverdueJob
 * (app/jobs/clock/mark_invoices_as_payment_overdue_job.rb) — hourly at
 * :25, fans out one Invoices::Payments::MarkOverdueJob per finalized,
 * unpaid invoice past its payment due date (unless a dispute was lost,
 * which settles the outcome already).
 */
class MarkInvoicesAsPaymentOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        Invoice::query()
            ->where('invoices.status', InvoiceStatus::Finalized->value)
            ->where('invoices.payment_status', '!=', InvoicePaymentStatus::Succeeded->value)
            ->where('invoices.payment_overdue', false)
            ->whereNull('invoices.payment_dispute_lost_at')
            ->where('invoices.payment_due_date', '<', now())
            // Rails iterates `in_batches(of: 1000, cursor: [:payment_due_date, :id])`
            // — the cursor order only shapes dispatch order, so the port
            // keeps the default id-keyed chunk walk.
            ->chunkById(1000, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    dispatch(new MarkOverdueJob(invoice: $invoice));
                }
            });
    }
}
