<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use App\Services\Invoices\RetryService;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::RetryFailedInvoicesJob
 * (app/jobs/clock/retry_failed_invoices_job.rb) — every 15 minutes, retry
 * the failed invoices whose error_details carry a provider-taxes "API
 * limit" message (transient aggregator throttling, worth a re-pull).
 */
class RetryFailedInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 4.hours`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        // Rails: Invoice.failed.joins(:error_details)
        //   .where("error_details.details ? 'tax_error_message'")
        //   .where("error_details.details ->> 'tax_error_message' ILIKE ?", "%API limit%")
        // The jsonb `?` top-level-key operator is spelled ?? through PDO
        // binding; jsonb_exists() is the same operator spelled safely.
        Invoice::query()
            ->where('invoices.status', InvoiceStatus::Failed->value)
            ->join('error_details', function ($join): void {
                // Rails' polymorphic join carries owner_type = 'Invoice'
                // (the stored Rails class name).
                $join->on('error_details.owner_id', '=', 'invoices.id')
                    ->where('error_details.owner_type', '=', 'Invoice');
            })
            ->whereNull('error_details.deleted_at')
            ->whereRaw("jsonb_exists(error_details.details, 'tax_error_message')")
            ->whereRaw("error_details.details ->> 'tax_error_message' ILIKE ?", ['%API limit%'])
            ->select('invoices.*')
            ->chunkById(1000, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    RetryService::call(invoice: $invoice);
                }
            }, 'invoices.id', 'id');
    }
}
