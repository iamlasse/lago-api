<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\RefreshDraftAndFinalizeService;

/**
 * Port of Rails' Invoices::FinalizeJob (app/jobs/invoices/finalize_job.rb)
 * — finalizes one invoice whose expected finalization date (or issuing
 * date) has come. Fanned out per invoice by Clock::FinalizeInvoicesJob.
 */
class FinalizeJob implements ShouldQueue
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
        RefreshDraftAndFinalizeService::call(invoice: $this->invoice);
    }
}
