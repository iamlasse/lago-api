<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Fee;
use App\Models\Invoice;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Models\InvoiceAppliedTax;

/**
 * Port of Rails' Invoices::ApplyTaxesService
 * (app/services/invoices/apply_taxes_service.rb) — invoice-level tax
 * rollup: one snapshot row per tax (invoices_taxes), with coupons pro-rated
 * at fee level taken into account.
 */
class ApplyTaxesService extends \App\Services\BaseService
{
    private ?array $cachedIndexedFees = null;

    public function __construct(private readonly Invoice $invoice) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('applied_taxes', 'invoice');
        $result->applied_taxes = [];

        $appliedTaxesAmountCents = 0;
        $taxesRate = 0.0;

        foreach ($this->applicableTaxes() as $tax) {
            $appliedTax = new InvoiceAppliedTax([
                'organization_id' => $this->invoice->organization_id,
                'tax_id' => $tax->id,
                'tax_description' => $tax->description,
                'tax_code' => $tax->code,
                'tax_name' => $tax->name,
                'tax_rate' => $tax->rate,
                'amount_currency' => $this->invoice->currency,
            ]);
            $appliedTax->invoice_id = $this->invoice->id;

            $taxAmountCents = $this->computeTaxAmountCents($tax);
            $appliedTax->fees_amount_cents = $this->feesAmountCents($tax);
            $appliedTax->amount_cents = MoneyMath::round((string) $taxAmountCents);

            // NOTE: when applied on user current usage, the invoice is not
            // created in the DB.
            if ($this->invoice->exists) {
                $appliedTax->save();
            }

            $appliedTaxesAmountCents += $taxAmountCents;
            $taxesRate += $this->proRatedTaxesRate($tax);

            $result->applied_taxes = array_merge($result->applied_taxes, [$appliedTax]);
        }

        $this->invoice->taxes_amount_cents = MoneyMath::round((string) $appliedTaxesAmountCents);
        $this->invoice->taxes_rate = MoneyMath::roundTo((string) $taxesRate, 5);

        $result->invoice = $this->invoice;

        return $result;
    }

    /**
     * NOTE: taxes applied on the fees might be created on the organization
     * but selected for a specific add-on, so not applied on the billing entity.
     */
    private function applicableTaxes()
    {
        $taxIds = array_keys($this->indexedFees());

        return \App\Models\Tax::query()
            ->where('organization_id', $this->invoice->organization_id)
            ->whereIn('id', $taxIds)
            ->get();
    }

    /**
     * NOTE: indexes the invoice fees by taxes: { tax_id => [fee, ...] }.
     *
     * @return array<string, list<Fee>>
     */
    private function indexedFees(): array
    {
        if ($this->cachedIndexedFees !== null) {
            return $this->cachedIndexedFees;
        }

        $indexed = [];

        foreach ($this->invoice->fees as $fee) {
            foreach ($fee->appliedTaxes as $appliedTax) {
                $indexed[$appliedTax->tax_id][] = $fee;
            }
        }

        return $this->cachedIndexedFees = $indexed;
    }

    /**
     * NOTE: Because coupons are applied before VAT, we take the coupons
     * amount pro-rated at fee level into account.
     */
    private function computeTaxAmountCents($tax): string
    {
        $total = '0';

        foreach ($this->indexedFees()[$tax->id] as $fee) {
            // Ruby: fee.sub_total * tax.rate summed, then .fdiv(100) — float math
            $total = bcadd($total, bcmul(
                $fee->subTotalExcludingTaxesAmountCents(),
                (string) $tax->rate,
                15,
            ), 15);
        }

        return (string) ((float) $total / 100);
    }

    /**
     * NOTE: a tax might not be applied to all fees of the invoice; the
     * invoice taxes_rate pro-ratas the fees attached to the tax over the
     * invoice fees_amount_cents.
     */
    private function proRatedTaxesRate($tax): float
    {
        $subTotal = (int) $this->invoice->sub_total_excluding_taxes_amount_cents;

        if ($subTotal > 0) {
            $feesRate = $this->feesAmountCents($tax) / $subTotal;
        } else {
            // NOTE: when the invoice has a 0 amount, the prorata is on the number of fees
            $feesRate = count($this->indexedFees()[$tax->id]) / max(count($this->invoice->fees), 1);
        }

        return $feesRate * (float) $tax->rate;
    }

    private function feesAmountCents($tax): int
    {
        return (int) collect($this->indexedFees()[$tax->id])->sum(
            fn (Fee $fee) => (int) MoneyMath::round($fee->subTotalExcludingTaxesAmountCents()),
        );
    }
}
