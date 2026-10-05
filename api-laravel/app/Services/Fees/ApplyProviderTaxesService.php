<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Models\FeeAppliedTax;
use App\Services\Integrations\Aggregator\Taxes\TaxResult;

/**
 * Port of Rails' Fees::ApplyProviderTaxesService
 * (app/services/fees/apply_provider_taxes_service.rb) — books a fee's
 * provider tax answer onto it: one applied tax per jurisdiction, the
 * provider's cents allocation kept verbatim in both amount columns.
 */
class ApplyProviderTaxesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Fee $fee,
        private readonly ?TaxResult $fee_taxes,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('applied_taxes');
        $result->applied_taxes = [];
        $applied = [];

        $appliedTaxes = $this->fee->appliedTaxes;

        if ($appliedTaxes->isNotEmpty()) {
            return $result;
        }

        if ($this->fee_taxes === null && ! $this->fee->taxable()) {
            return $result;
        }

        if ($this->fee_taxes === null) {
            // NOTE: A fee with no amount is left out of the provider request, so
            // having no entry in the response is expected. Any other fee missing
            // from a successful response means the response is incomplete, and
            // taxing it as zero would under-charge it silently.
            $result->serviceFailure(
                code: 'fee_missing_from_tax_response',
                message: "Fee {$this->fee->id} is missing from the provider tax response",
            );

            return $result;
        }

        $taxBreakdown = $this->fee_taxes->taxBreakdown;
        $allocatedAmounts = $this->fee_taxes->allocatedAmounts();
        $baseRate = $this->taxes_base_rate($taxBreakdown[0] ?? null);

        foreach (array_map(null, $taxBreakdown, $allocatedAmounts) as [$tax, $amountCents]) {
            $appliedTax = new FeeAppliedTax([
                'organization_id' => $this->fee->organization_id,
                'tax_description' => $tax->type,
                'tax_code' => \Illuminate\Support\Str::slug((string) $tax->name, separator: '_'),
                'tax_name' => $tax->name,
                'tax_rate' => (float) $tax->rate * 100,
                'amount_currency' => $this->fee->amount_currency,
            ]);

            // Rails: `fee.applied_taxes << applied_tax` — built in memory with
            // the association set, persisted below only when the fee is.
            $appliedTax->fee_id = $this->fee->id;
            $appliedTaxes->push($appliedTax);
            $this->fee->setRelation('appliedTaxes', $appliedTaxes);

            $appliedTax->amount_cents = $amountCents;
            $appliedTax->precise_amount_cents = (string) $tax->taxAmount;

            if ($this->fee->exists) {
                $appliedTax->save();
            }

            $applied[] = $appliedTax;
        }

        $this->fee->taxes_amount_cents = array_sum(array_map(
            fn (FeeAppliedTax $tax) => (int) $tax->amount_cents,
            $applied,
        ));

        $preciseSum = '0';
        foreach ($applied as $tax) {
            $preciseSum = MoneyMath::add($preciseSum, (string) $tax->precise_amount_cents);
        }
        $this->fee->taxes_precise_amount_cents = $preciseSum;

        $this->fee->taxes_rate = array_sum(array_map(
            fn (FeeAppliedTax $tax) => (float) $tax->tax_rate,
            $applied,
        ));
        $this->fee->taxes_base_rate = $baseRate;

        $result->applied_taxes = $applied;

        return $result;
    }

    /**
     * Rails: `taxes_base_rate` — the share of the fee's base the provider
     * actually taxed (a jurisdiction can reduce the taxable base); 1 when
     * the provider booked the full rate, nil-safe to 1 without a breakdown.
     */
    private function taxes_base_rate(?object $tax): int|float
    {
        if ($tax === null) {
            return 1;
        }

        $taxRate = (float) $tax->rate * 100;
        $taxAmountCents = (int) $this->fee->subTotalExcludingTaxesAmountCents() * $taxRate / 100;

        if ($tax->taxAmount < $taxAmountCents) {
            return $taxAmountCents !== 0 ? $tax->taxAmount / $taxAmountCents : 1;
        }

        return 1;
    }
}
