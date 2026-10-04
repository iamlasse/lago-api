<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use App\Jobs\Invoices\FinalizeJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::FinalizeInvoicesJob
 * (app/jobs/clock/finalize_invoices_job.rb) — hourly at :20, fans out one
 * Invoices::FinalizeJob per draft invoice whose expected finalization date
 * (or issuing date) has arrived.
 */
class FinalizeInvoicesJob implements ShouldQueue
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
            ->readyToBeFinalized()
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    FinalizeJob::dispatch($invoice);
                }
            });
    }
}
