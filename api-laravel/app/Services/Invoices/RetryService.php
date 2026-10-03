<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Services\BaseResult;
use App\Enums\InvoiceTaxStatus;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Invoices::RetryService
 * (app/services/invoices/retry_service.rb) — reopens a failed invoice for a
 * payment retry (pending, or open while a subscription is payment-gated).
 *
 * TODO(port): Invoices::ProviderTaxes::PullTaxesAndApplyJob (provider
 * taxes are a later milestone).
 */
class RetryService extends \App\Services\BaseService
{
    public function __construct(private readonly ?Invoice $invoice)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        $invalidStatus = false;
        $updated = null;

        // Cancelling a payment-gated subscription closes this invoice under
        // the same row lock, so the status is read again here rather than
        // trusted from before the lock was taken.
        DB::transaction(function () use (&$invalidStatus, &$updated): void {
            $invoice = Invoice::query()
                ->whereKey($this->invoice->id)
                ->lockForUpdate()
                ->first()
                ?? $this->invoice;

            if ($invoice->statusEnum() === InvoiceStatus::Failed) {
                $invoice->status = $invoice->subscriptions()
                    ->get()
                    ->contains(fn ($subscription) => $subscription->gated())
                    ? InvoiceStatus::Open
                    : InvoiceStatus::Pending;
                $invoice->tax_status = InvoiceTaxStatus::Pending->value;
                $invoice->save();

                $updated = $invoice;
            } else {
                $invalidStatus = true;
            }
        });

        if ($invalidStatus) {
            return $result->notAllowedFailure('invalid_status');
        }

        // TODO(port): Invoices::ProviderTaxes::PullTaxesAndApplyJob
        // .perform_later(invoice:).

        $result->invoice = $updated ?? $this->invoice;

        return $result;
    }
}
