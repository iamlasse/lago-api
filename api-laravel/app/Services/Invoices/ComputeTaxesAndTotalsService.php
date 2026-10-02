<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Services\BaseResult;
use App\Enums\InvoiceTaxStatus;

/**
 * Port of Rails' Invoices::ComputeTaxesAndTotalsService
 * (app/services/invoices/compute_taxes_and_totals_service.rb).
 *
 * TODO(port): provider taxation (customer.tax_customer + VIES check) —
 * Invoices::ProviderTaxes::PullTaxesAndApplyJob and
 * Invoices::EnsureCompletedViesCheckService arrive with the integrations
 * milestone; the local-taxes path is implemented.
 */
class ComputeTaxesAndTotalsService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly bool $finalizing = true,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice', 'non_invoiceable_fees');

        // Apply local taxes
        ComputeAmountsFromFees::call(invoice: $this->invoice)->raiseIfError();

        $result->invoice = $this->invoice;

        return $result;
    }

    /**
     * Port of `set_pending_tax_status!` — public so the provider-taxes
     * TODO(port) path can use it when integrations land.
     */
    public function setPendingTaxStatus(): void
    {
        if ($this->finalizing) {
            $this->invoice->status = $this->invoice->subscriptionGated()
                ? InvoiceStatus::Open
                : InvoiceStatus::Pending;
        }

        $this->invoice->tax_status = InvoiceTaxStatus::Pending->value;
        $this->invoice->save();
    }
}
