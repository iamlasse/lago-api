<?php

declare(strict_types=1);

namespace App\Jobs\Integrations\Aggregator\Invoices;

use App\Models\Invoice;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Integrations\Aggregator\Invoices\CreateService;

/**
 * Port of Rails' Integrations::Aggregator::Invoices::CreateJob
 * (app/jobs/integrations/aggregator/invoices/create_job.rb).
 *
 * TODO(port): `unique :until_executed`, the ConcurrencyThrottlable concern,
 * the retry_on table (HttpError 3 attempts polynomial, RequestLimitError
 * 100, ThrottlingError 25, Net::ReadTimeout with the Netsuite 6-minute
 * delay) and the ReconcileService look-upstream leg — the worker's $tries
 * covers it until the queue hardening slice lands and the reconcile
 * collector is ported.
 */
class CreateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly bool $find_first = false,
    ) {
        $this->onQueue('integrations');
    }

    /**
     * Rails: `invoice.should_sync_invoice?` — the emission guard of the ten
     * invoice services that enqueue this job:
     * `!self_billed && finalized? &&
     * customer.integration_customers.accounting_kind.any? { _1.integration.sync_invoices }`.
     *
     * (The predicate lives on the Rails Invoice model; the port keeps it
     * here — app/Models/Invoice.php is frozen outside appended relations.)
     */
    public static function shouldSyncInvoice(Invoice $invoice): bool
    {
        if ($invoice->self_billed || ! $invoice->isFinalized()) {
            return false;
        }

        return $invoice->customer
            ->integrationCustomers()
            ->accountingKind()
            ->get()
            ->contains(fn ($integrationCustomer) => (bool) $integrationCustomer->integration?->getFromSettings('sync_invoices'));
    }

    /** Rails: `perform_later(invoice:) if invoice.should_sync_invoice?`. */
    public static function dispatchIfShouldSync(Invoice $invoice): void
    {
        if (self::shouldSyncInvoice($invoice)) {
            self::dispatch($invoice);
        }
    }

    public function handle(): void
    {
        CreateService::callBang(invoice: $this->invoice);
    }
}
