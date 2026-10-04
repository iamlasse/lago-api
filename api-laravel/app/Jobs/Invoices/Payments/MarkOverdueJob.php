<?php

declare(strict_types=1);

namespace App\Jobs\Invoices\Payments;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\Payments\MarkOverdueService;

/**
 * Port of Rails' Invoices::Payments::MarkOverdueJob
 * (app/jobs/invoices/payments/mark_overdue_job.rb) — flags one invoice as
 * payment overdue, fanned out by Clock::MarkInvoicesAsPaymentOverdueJob.
 */
class MarkOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly Invoice $invoice)
    {
        $this->onQueue('low_priority');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 24 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        MarkOverdueService::call(invoice: $this->invoice);
    }
}
