<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use App\Jobs\Invoices\RefreshDraftJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::RefreshDraftInvoicesJob
 * (app/jobs/clock/refresh_draft_invoices_job.rb) — every 5 minutes, fans
 * out one Invoices::RefreshDraftJob per draft invoice flagged
 * ready_to_be_refreshed that still has an active subscription.
 */
class RefreshDraftInvoicesJob implements ShouldQueue
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
            ->readyToBeRefreshed()
            ->withActiveSubscriptions()
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    RefreshDraftJob::dispatch(invoice: $invoice);
                }
            });
    }
}
