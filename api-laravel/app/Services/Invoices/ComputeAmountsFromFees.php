<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Services\BaseResult;
use App\Services\Fees\ApplyTaxesService as FeeApplyTaxesService;

/**
 * Port of Rails' Invoices::ComputeAmountsFromFees
 * (app/services/invoices/compute_amounts_from_fees.rb) — the invoice-level
 * cents rollup from the fee-level amounts and taxes.
 *
 * TODO(port): provider taxes (Anrok/Vertex) arrive with the integrations
 * milestone; the local-taxes path is implemented.
 */
class ComputeAmountsFromFees extends \App\Services\BaseService
{
    /**
     * @param  list<\App\Services\Integrations\Aggregator\Taxes\TaxResult>|null  $provider_taxes
     */
    public function __construct(
        private readonly Invoice $invoice,
        private readonly ?array $provider_taxes = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->shouldApplyFeeTaxes()) {
            foreach ($this->invoice->fees as $fee) {
                if ($this->shouldApplyProviderTaxes()) {
                    \App\Services\Fees\ApplyProviderTaxesService::call(
                        fee: $fee,
                        fee_taxes: $this->fee_taxes($fee),
                    )->raiseIfError();
                } else {
                    FeeApplyTaxesService::call(fee: $fee)->raiseIfError();
                }

                if ($this->invoice->exists) {
                    $fee->save();
                }
            }
        }

        $this->invoice->fees_amount_cents = (int) $this->invoice->fees->sum('amount_cents');
        $this->invoice->coupons_amount_cents = (int) $this->invoice->credits
            ->filter(fn ($credit) => $credit->applied_coupon_id !== null)
            ->sum('amount_cents');

        $this->invoice->sub_total_excluding_taxes_amount_cents =
            (int) $this->invoice->fees_amount_cents
            - (int) $this->invoice->progressive_billing_credit_amount_cents
            - (int) $this->invoice->coupons_amount_cents;

        if ($this->shouldApplyProviderTaxes()) {
            \App\Services\Invoices\ApplyProviderTaxesService::call(
                invoice: $this->invoice,
                provider_taxes: $this->provider_taxes,
            )->raiseIfError();
        } else {
            ApplyTaxesService::call(invoice: $this->invoice)->raiseIfError();
        }

        $this->invoice->sub_total_including_taxes_amount_cents =
            (int) $this->invoice->sub_total_excluding_taxes_amount_cents
            + (int) $this->invoice->taxes_amount_cents;

        $this->invoice->total_amount_cents =
            (int) $this->invoice->sub_total_including_taxes_amount_cents
            - (int) $this->invoice->credit_notes_amount_cents;

        $result->invoice = $this->invoice;

        return $result;
    }

    private function shouldApplyProviderTaxes(): bool
    {
        return $this->provider_taxes !== null
            && $this->invoice->customer->taxCustomer() !== null
            && $this->invoice->shouldApplyProviderTax();
    }

    /**
     * Rails: `fee_taxes(fee)` — the provider answer whose item_id matches
     * the fee.
     */
    private function fee_taxes(object $fee): ?object
    {
        foreach ($this->provider_taxes ?? [] as $item) {
            if ($item->itemId == $fee->id) {
                return $item;
            }
        }

        return null;
    }

    private function shouldApplyFeeTaxes(): bool
    {
        // TODO(port): advance_charges invoices skip fee taxes until the
        // advance-charges invoice type is ported.
        return true;
    }
}
