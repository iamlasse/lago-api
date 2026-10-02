<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Services\BaseResult;

/**
 * Port of Rails' Invoices::TransitionToFinalStatusService
 * (app/services/invoices/transition_to_final_status_service.rb) — decides
 * between finalized and closed depending on the zero-amount invoice setting.
 */
class TransitionToFinalStatusService extends \App\Services\BaseService
{
    public function __construct(private readonly Invoice $invoice) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');
        $result->invoice = $this->invoice;

        // Keep open for payment-gated invoices awaiting payment or tax
        // resolution. Tax pending matters because totals are not yet computed
        // — falling through would treat the invoice as zero-amount and
        // finalize it prematurely.
        if ($this->invoice->subscriptionGated()
            && ((int) $this->invoice->total_amount_cents > 0 || $this->invoice->taxPending())) {
            return $result;
        }

        if ($this->shouldFinalizeInvoice()) {
            FinalizeService::callBang(invoice: $this->invoice);
        } else {
            $this->invoice->status = InvoiceStatus::Closed;
        }

        return $result;
    }

    public function shouldFinalizeInvoice(): bool
    {
        if ((int) $this->invoice->fees_amount_cents !== 0) {
            return true;
        }

        $customerSetting = $this->invoice->customer->finalize_zero_amount_invoice;

        if ($customerSetting === \App\Enums\FinalizeZeroAmountInvoice::Inherit) {
            // billing_entity.finalize_zero_amount_invoice is a boolean.
            return (bool) $this->invoice->billingEntity->finalize_zero_amount_invoice;
        }

        return $customerSetting === \App\Enums\FinalizeZeroAmountInvoice::Finalize;
    }
}
