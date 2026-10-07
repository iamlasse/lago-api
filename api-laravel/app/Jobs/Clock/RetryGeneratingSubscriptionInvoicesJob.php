<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Invoice;
use App\Enums\InvoiceType;
use App\Enums\InvoiceStatus;
use Illuminate\Bus\Queueable;
use App\Jobs\BillSubscriptionJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::RetryGeneratingSubscriptionInvoicesJob
 * (app/jobs/clock/retry_generating_subscription_invoices_job.rb) — hourly
 * at :30, re-enqueues billing for subscription invoices stuck in the
 * generating state for over a day (a billing run crashed mid-invoice).
 *
 * TODO(port): the ErrorDetail exclusion (ids of invoices carrying an
 * invoice_generation_error) — the ErrorDetail model is not ported, and no
 * error can be recorded today, so the exclusion is a no-op.
 */
class RetryGeneratingSubscriptionInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Rails: THRESHOLD = -> { 1.day.ago }. */
    public const THRESHOLD = '-1 day';

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
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        // TODO(port): ErrorDetail.invoice_generation_error owner ids — an
        // invoice with a recorded generation error is NOT retried here
        // (BillSubscriptionJob's failure path creates the ErrorDetail).
        $erroredInvoiceIds = [];

        Invoice::query()
            ->where('invoice_type', InvoiceType::Subscription->value)
            ->where('status', InvoiceStatus::Generating->value)
            ->whereNotIn('id', $erroredInvoiceIds)
            ->where('created_at', '<', now()->modify(self::THRESHOLD))
            ->chunkById(500, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $this->retryInvoice($invoice);
                }
            });
    }

    private function retryInvoice(Invoice $invoice): void
    {
        $invoiceSubscriptions = $invoice->invoiceSubscriptions;

        if ($invoiceSubscriptions->isEmpty()) {
            return;
        }

        $invoicingReasons = $invoiceSubscriptions
            ->pluck('invoicing_reason')
            ->filter()
            ->unique()
            ->values();

        $invoicingReason = $invoicingReasons->count() === 1
            ? (string) $invoicingReasons->first()
            : 'upgrading';

        if ($invoicingReason === 'in_advance_charge') {
            return;
        }

        dispatch(new BillSubscriptionJob(
            $invoice->subscriptions->all(),
            // Rails: invoice_subscriptions.first.timestamp.to_i
            (int) \Illuminate\Support\Facades\Date::parse($invoiceSubscriptions->first()->timestamp)->getTimestamp(),
            $invoicingReason,
            $invoice->id,
            $invoice->skip_charges
        ));
    }
}
