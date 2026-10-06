<?php

declare(strict_types=1);

namespace App\Jobs\Invoices\Payments;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\Payments\RetryBatchService;

/**
 * Port of Rails' Invoices::Payments::RetryAllJob
 * (app/jobs/invoices/payments/retry_all_job.rb) — processes the retry-all
 * payments batch: Payments::RetryBatchService#call with the recorded ids.
 */
class RetryAllJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $organizationId,
        public readonly array $invoiceIds,
    ) {
        // Rails queue: `payments` when SIDEKIQ_PAYMENTS is set, `invoices`
        // otherwise.
        $this->onQueue(filter_var(env('SIDEKIQ_PAYMENTS'), FILTER_VALIDATE_BOOL) ? 'payments' : 'invoices');
    }

    public function handle(): void
    {
        (new RetryBatchService(organizationId: $this->organizationId))
            ->callIds($this->invoiceIds)
            ->raiseIfError();
    }
}
