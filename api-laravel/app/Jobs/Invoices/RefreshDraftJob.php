<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\RefreshDraftService;

/**
 * Port of Rails' Invoices::RefreshDraftJob
 * (app/jobs/invoices/refresh_draft_job.rb) — refreshes one draft invoice
 * (progressive billing / usage updates) fanned out by
 * Clock::RefreshDraftInvoicesJob.
 */
class RefreshDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly Invoice $invoice)
    {
        // Rails queue: `billing` when SIDEKIQ_BILLING is set, `invoices` otherwise.
        $this->onQueue(filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'invoices');
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 12.hours`. */
    public function uniqueFor(): int
    {
        return 12 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        // if this has already been set to false, we can skip the job
        if (! $this->invoice->ready_to_be_refreshed) {
            return;
        }

        RefreshDraftService::callBang(invoice: $this->invoice);
    }
}
