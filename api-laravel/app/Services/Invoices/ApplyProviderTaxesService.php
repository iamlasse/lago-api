<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use Illuminate\Support\Str;
use App\Services\BaseResult;
use App\Models\InvoiceAppliedTax;
use App\Services\Integrations\Aggregator\Taxes\TaxResult;
use App\Services\Integrations\Aggregator\Taxes\TaxBreakdownItem;
use App\Services\Integrations\Aggregator\Taxes\Invoices\CreateService;
use App\Services\Integrations\Aggregator\Taxes\Invoices\CreateDraftService;

/**
 * Port of Rails' Invoices::ApplyProviderTaxesService
 * (app/services/invoices/apply_provider_taxes_service.rb) — the
 * invoice-level tax rows built from the provider's per-fee answers: taxes
 * grouped by (type, name, rate), the fee-allocated cents preserved, the
 * rate prorated over the taxed fees' share of the invoice subtotal.
 */
class ApplyProviderTaxesService extends \App\Services\BaseService
{
    private array $indexedFeeTaxes;

    public function __construct(
        private readonly \App\Models\Invoice $invoice,
        /** @var list<TaxResult>|null */
        private readonly ?array $provider_taxes = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('applied_taxes', 'invoice');
        $applied = [];

        $providerTaxes = $this->provider_taxes ?? $this->fetch_provider_taxes_result();

        $appliedTaxesAmountCents = 0;
        $taxesRate = 0.0;

        foreach ($this->applicable_taxes($providerTaxes) as $key => $tax) {
            $feeTaxes = $this->indexed_fee_taxes()[$key];
            $fees = array_map(fn (array $entry) => $entry[0], $feeTaxes);
            $taxRate = is_string($tax->rate) ? (float) $tax->rate * 100 : (float) $tax->rate;

            $appliedTax = new InvoiceAppliedTax([
                'organization_id' => $this->invoice->organization_id,
                'tax_description' => $tax->type,
                'tax_code' => Str::slug((string) $tax->name, separator: '_'),
                'tax_name' => $tax->name,
                'tax_rate' => $taxRate,
                'amount_currency' => $this->invoice->currency,
            ]);

            $invoiceAppliedTaxes = $this->invoice->appliedTaxes;
            $invoiceAppliedTaxes->push($appliedTax);
            $this->invoice->setRelation('appliedTaxes', $invoiceAppliedTaxes);

            // Preserve the cents already allocated to fees by the provider calculation.
            $taxAmountCents = array_sum(array_map(
                fn (array $entry) => (int) $entry[1]->amount_cents,
                $feeTaxes,
            ));
            $appliedTax->fees_amount_cents = $this->fees_amount_cents($fees);
            $appliedTax->taxable_base_amount_cents = (int) round($this->taxable_base_amount_cents($fees));
            $appliedTax->amount_cents = (int) round($taxAmountCents);

            // NOTE: when applied on user current usage, the invoice is
            //       not created in DB
            $appliedTax->invoice_id = $this->invoice->id;

            if ($this->invoice->exists) {
                $appliedTax->save();
            }

            $appliedTaxesAmountCents += $taxAmountCents;
            $taxesRate += $this->pro_rated_taxes_rate($tax, $fees);

            $applied[] = $appliedTax;
        }

        $this->invoice->taxes_amount_cents = (int) round($appliedTaxesAmountCents);
        $this->invoice->taxes_rate = round($taxesRate, 5);
        $result->applied_taxes = $applied;
        $result->invoice = $this->invoice;

        return $result;
    }

    /**
     * Rails: `applicable_taxes` — the provider taxes' breakdown entries
     * de-duplicated by (type, parameterized name, rate).
     *
     * @param  list<TaxResult>  $providerTaxes
     * @return array<string, TaxBreakdownItem>
     */
    private function applicable_taxes(array $providerTaxes): array
    {
        $output = [];

        foreach ($providerTaxes as $taxResult) {
            foreach ($taxResult->taxBreakdown as $tax) {
                $key = $this->tax_key(name: $tax->name, rate: $tax->rate, type: $tax->type);
                $output[$key] ??= $tax;
            }
        }

        return $output;
    }

    /**
     * Rails: `indexed_fee_taxes` — the fee applied taxes (booked by
     * Fees::ApplyProviderTaxesService) indexed by the same tax key, each
     * entry the [fee, applied_tax] pair.
     *
     * @return array<string, list<array{0: \App\Models\Fee, 1: \App\Models\FeeAppliedTax}>>
     */
    private function indexed_fee_taxes(): array
    {
        if (isset($this->indexedFeeTaxes)) {
            return $this->indexedFeeTaxes;
        }

        $output = [];

        foreach ($this->invoice->fees as $fee) {
            foreach ($fee->appliedTaxes as $appliedTax) {
                $key = $this->tax_key(
                    name: $appliedTax->tax_name,
                    rate: $appliedTax->tax_rate,
                    type: $appliedTax->tax_description,
                );
                $output[$key] ??= [];
                $output[$key][] = [$fee, $appliedTax];
            }
        }

        return $this->indexedFeeTaxes = $output;
    }

    /**
     * @param  list<\App\Models\Fee>  $fees
     */
    private function pro_rated_taxes_rate(TaxBreakdownItem $tax, array $fees): float
    {
        $taxRate = is_string($tax->rate) ? (float) $tax->rate * 100 : (float) $tax->rate;

        $subTotal = (int) $this->invoice->sub_total_excluding_taxes_amount_cents;

        $feesRate = $subTotal > 0
            ? $this->fees_amount_cents($fees) / $subTotal
            // NOTE: when invoice have a 0 amount. The prorata is on the number of fees.
            //       Fees with no taxable base are not reported and carry no tax row, so they are
            //       out of the denominator too; counting them would dilute the rate below the one
            //       the provider returned.
            : count($fees) / $this->taxed_fees_count();

        return $feesRate * $taxRate;
    }

    private function taxed_fees_count(): int
    {
        $fees = [];

        foreach ($this->indexed_fee_taxes() as $entries) {
            foreach ($entries as [$fee, $appliedTax]) {
                $fees[$fee->id ?? spl_object_id($fee)] = $fee;
            }
        }

        return count($fees);
    }

    /**
     * @param  list<\App\Models\Fee>  $fees
     */
    private function fees_amount_cents(array $fees): int
    {
        return array_sum(array_map(
            fn ($fee) => (int) $fee->subTotalExcludingTaxesAmountCents(),
            $fees,
        ));
    }

    /**
     * @param  list<\App\Models\Fee>  $fees
     */
    private function taxable_base_amount_cents(array $fees): float
    {
        return array_sum(array_map(
            fn ($fee) => (int) $fee->subTotalExcludingTaxesAmountCents() * (float) $fee->taxes_base_rate,
            $fees,
        ));
    }

    /** @return list<TaxResult> */
    private function fetch_provider_taxes_result(): array
    {
        $taxesResult = ($this->invoice->isDraft() || $this->invoiceTypeIsAdvanceCharges())
            ? CreateDraftService::call(invoice: $this->invoice)
            : CreateService::call(invoice: $this->invoice);

        /** @var list<TaxResult> $fees */
        return $taxesResult->raiseIfError()->fees;
    }

    private function invoiceTypeIsAdvanceCharges(): bool
    {
        return $this->invoice->invoice_type === \App\Enums\InvoiceType::AdvanceCharges->value
            || $this->invoice->invoice_type === \App\Enums\InvoiceType::AdvanceCharges;
    }

    private function tax_key(string|int|float|null $name, string|int|float|null $rate, ?string $type): string
    {
        $taxRate = is_string($rate) ? (float) $rate * 100 : (float) $rate;

        return $type.'-'.Str::slug((string) $name, separator: '_').'-'.$taxRate;
    }
}
