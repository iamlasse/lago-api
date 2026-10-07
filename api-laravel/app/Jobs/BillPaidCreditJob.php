<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use App\Models\WalletTransaction;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Invoices\PaidCreditService;

/**
 * Port of Rails' BillPaidCreditJob (app/jobs/bill_paid_credit_job.rb) —
 * settles a purchased wallet transaction by billing it onto its credit
 * invoice.
 *
 * Rails retries on Sequenced::SequenceError (15 polynomial waits); the
 * port's worker retries per $tries.
 *
 * The payment-provider callbacks that enqueue this in production are an
 * M-later slice; tests settle pending purchased transactions by dispatching
 * the job directly.
 */
class BillPaidCreditJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 15;

    public function __construct(
        public readonly WalletTransaction $walletTransaction,
        public readonly mixed $timestamp,
        public readonly ?Invoice $invoice = null,
    ) {
        $this->onQueue('high_priority');
    }

    public function handle(): void
    {
        $result = PaidCreditService::call(
            walletTransaction: $this->walletTransaction,
            timestamp: $this->timestamp,
            invoice: $this->invoice,
        );

        if ($result->success()) {
            return;
        }

        // NOTE: retry with the invoice a previous failed attempt may already
        // have created — unless the failure happened past the invoice stage.
        if ($this->invoice !== null || $result->invoice === null || ! $result->invoice->isGenerating()) {
            $result->raiseIfError();
        }

        dispatch(new self($this->walletTransaction, $this->timestamp, $result->invoice))->delay(now()->addSeconds(3));
    }
}
