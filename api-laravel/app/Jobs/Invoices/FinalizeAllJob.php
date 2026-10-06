<?php

declare(strict_types=1);

namespace App\Jobs\Invoices;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\FinalizeBatchService;

/**
 * Port of Rails' Invoices::FinalizeAllJob
 * (app/jobs/invoices/finalize_all_job.rb) — processes the finalize-all
 * batch: FinalizeBatchService#call with the recorded invoice ids. Rails
 * re-raises everything but a tax_error validation failure.
 */
class FinalizeAllJob implements ShouldQueue
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
        $result = (new FinalizeBatchService(organization: $this->organization))
            ->callIds($this->invoiceIds);

        // Rails: result.raise_if_error! unless tax_error?(result) — a
        // tax_error validation failure is swallowed.
        $error = $result->getError();

        $taxError = $error instanceof \App\Services\Failures\ValidationFailure
            && is_array($error->messages)
            && isset($error->messages['tax_error']);

        if ($result->failure() && ! $taxError) {
            $result->raiseIfError();
        }
    }
}
