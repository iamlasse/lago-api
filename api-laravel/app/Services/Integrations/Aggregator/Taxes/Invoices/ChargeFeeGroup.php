<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes\Invoices;

use App\Models\Fee;
use App\Support\MoneyMath;
use App\Services\Integrations\Aggregator\Taxes\TaxResult;
use App\Services\Integrations\Aggregator\Taxes\ChargeGroup;
use App\Services\Integrations\Aggregator\Taxes\TaxBreakdownItem;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::Invoices::ChargeFeeGroup
 * (app/services/integrations/aggregator/taxes/invoices/
 * charge_fee_group.rb) — quacks like a Fee so the provider payloads need no
 * notion of grouping: one line item per charge, taxes split back over the
 * member fees pro rata to their taxable base.
 */
class ChargeFeeGroup
{
    private int $subTotal;

    /**
     * @param  list<Fee>  $fees
     */
    public function __construct(public readonly array $fees) {}

    /**
     * Rails: `.build(fees)` — group the fees by charge; singleton fees pass
     * through untouched.
     *
     * @param  list<Fee>  $fees
     * @return list<Fee|ChargeFeeGroup>
     */
    public static function build(array $fees): array
    {
        return ChargeGroup::byCharge($fees);
    }

    /** Rails: delegate :charge_id, :billable_metric, to: "fees.first". */
    public function chargeId(): ?string
    {
        return $this->fees[0]->charge_id;
    }

    public function billableMetric(): ?object
    {
        return $this->fees[0]->billable_metric;
    }

    public function id(): ?string
    {
        return null;
    }

    public function itemKey(): ?string
    {
        return $this->chargeId();
    }

    public function itemId(): ?string
    {
        return $this->itemKey();
    }

    public function isCharge(): bool
    {
        return true;
    }

    /** Rails: `units` — the summed member units. */
    public function units(): string
    {
        $sum = '0';

        foreach ($this->fees as $fee) {
            $sum = MoneyMath::add($sum, (string) $fee->units);
        }

        return $sum;
    }

    public function amountCents(): int
    {
        return (int) array_sum(array_map(fn (Fee $fee) => (int) $fee->amount_cents, $this->fees));
    }

    public function subTotalExcludingTaxesAmountCents(): int
    {
        if (! isset($this->subTotal)) {
            $this->subTotal = array_sum(array_map(
                fn (Fee $fee) => (int) $fee->subTotalExcludingTaxesAmountCents(),
                $this->fees,
            ));
        }

        return $this->subTotal;
    }

    /**
     * Rails: `split_taxes(group_taxes)` — one TaxResult per member fee,
     * jurisdiction by jurisdiction, each split pro rata to the fee taxable
     * bases (precise unrounded shares preserved; booked cents allocated by
     * the shared allocator).
     *
     * @return list<TaxResult>
     */
    public function splitTaxes(TaxResult $groupTaxes): array
    {
        $breakdowns = array_map(fn (Fee $fee) => [], $this->fees);

        $allocatedAmounts = $groupTaxes->allocatedAmounts();
        $weights = $this->feeWeights();

        foreach (array_map(null, $groupTaxes->taxBreakdown, $allocatedAmounts) as [$tax, $amount]) {
            $preciseShares = $this->preciseShares($tax->taxAmount, $weights);
            $bookedShares = \App\Support\Allocation::call($amount, $weights);

            foreach ($breakdowns as $index => $breakdown) {
                $breakdowns[$index][] = $tax->with(
                    taxAmount: $preciseShares[$index],
                    allocatedAmountCents: $bookedShares[$index],
                );
            }
        }

        $results = [];
        foreach ($this->fees as $index => $fee) {
            $results[] = new TaxResult(
                itemKey: $fee->itemKey(),
                itemId: $fee->id ?? $fee->itemKey(),
                itemCode: $groupTaxes->itemCode,
                amountCents: (int) $fee->subTotalExcludingTaxesAmountCents(),
                taxAmountCents: array_sum(array_map(
                    fn (TaxBreakdownItem $item) => (int) $item->allocatedAmountCents,
                    $breakdowns[$index],
                )),
                taxBreakdown: $breakdowns[$index],
            );
        }

        return $results;
    }

    /**
     * Rails: `fee_weights` — the member taxable bases.
     *
     * @return list<int>
     */
    private function feeWeights(): array
    {
        return array_map(fn (Fee $fee) => (int) $fee->subTotalExcludingTaxesAmountCents(), $this->fees);
    }

    /**
     * Rails: `Allocation.precise(tax.tax_amount, fee_weights)` — the
     * unrounded proportional shares (fractional cents preserved).
     *
     * @param  list<int>  $weights
     * @return list<string>
     */
    private function preciseShares(int|float|null $total, array $weights): array
    {
        if ($total === null || $total === 0) {
            return array_map(fn ($w) => '0', $weights);
        }

        $totalWeight = '0';
        foreach ($weights as $weight) {
            $totalWeight = MoneyMath::add($totalWeight, (string) $weight);
        }

        if (MoneyMath::compare($totalWeight, '0') === 0) {
            return array_map(fn ($w) => '0', $weights);
        }

        return array_map(
            fn (int $weight): string => MoneyMath::fdiv(MoneyMath::mul((string) $total, (string) $weight), $totalWeight),
            $weights,
        );
    }
}
