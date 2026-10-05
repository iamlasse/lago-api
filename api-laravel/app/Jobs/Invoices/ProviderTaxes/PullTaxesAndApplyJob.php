<?php

declare(strict_types=1);

namespace App\Jobs\Invoices\ProviderTaxes;

use App\Models\Invoice;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Invoices\ProviderTaxes\PullTaxesAndApplyService;

/**
 * Port of Rails' Invoices::ProviderTaxes::PullTaxesAndApplyJob
 * (app/jobs/invoices/provider_taxes/pull_taxes_and_apply_job.rb).
 *
 * Rails' retry_on table (ThrottlingError 25 attempts, HttpError / SSL /
 * timeouts / aggregator retryable errors 6, locks + stale objects, Sequenced
 * 15) relies on the exception classes raised through the aggregator stack;
 * the Laravel worker retries per the queue connection's $tries — the
 * retryable exception map is TODO(port) with the queue hardening slice.
 *
 * TODO(port): `unique :until_executed` (lock_ttl 12.hours) — Rails' unique
 * job locking; Laravel's ShouldBeUnique locks only for a single job
 * identity at dispatch time.
 */
class PullTaxesAndApplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invoice $invoice)
    {
        $this->onQueue('providers');
    }

    public function handle(): void
    {
        PullTaxesAndApplyService::callBang(invoice: $this->invoice);
    }
}
