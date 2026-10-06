<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\RetryBatchService;

/**
 * Port of Rails' Invoices::RetryAllJob
 * (app/jobs/invoices/retry_all_job.rb) — processes the retry-all batch:
 * RetryBatchService#call with the recorded invoice ids.
 */
class RetryAllJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly object $organization,
        public readonly array $invoiceIds,
    ) {
        // Rails queue: `invoices`.
        $this->onQueue('invoices');
    }

    public function handle(): void
    {
        (new RetryBatchService(organization: $this->organization))
            ->callIds($this->invoiceIds)
            ->raiseIfError();
    }
}
