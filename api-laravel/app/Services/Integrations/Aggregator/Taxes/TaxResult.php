<?php

declare(strict_types=1);

namespace App\Services\Integrations\Aggregator\Taxes;

use App\Support\Allocation;

/**
 * Port of Rails' Integrations::Aggregator::Taxes::TaxResult (a Data.define)
 * and its TaxBreakdownItem — one fee's provider tax answer: the booked total
 * plus the per-jurisdiction breakdown.
 */
class TaxResult
{
    /**
     * @param  list<TaxBreakdownItem>  $taxBreakdown
     */
    public function __construct(
        public readonly int|string|null $itemKey,
        public readonly int|string|null $itemId,
        public readonly ?string $itemCode,
        public readonly int|string|null $amountCents,
        public readonly int|float|string|null $taxAmountCents,
        public readonly array $taxBreakdown,
    ) {}

    /**
     * Rails: `allocated_amounts` — preserve the allocations made for a
     * grouped charge; otherwise reconcile the provider's line total with its
     * jurisdiction breakdown.
     *
     * @return list<int>
     */
    public function allocatedAmounts(): array
    {
        if ($this->taxBreakdown !== [] && ! in_array(null, array_map(
            fn (TaxBreakdownItem $tax) => $tax->allocatedAmountCents,
            $this->taxBreakdown,
        ), true)) {
            return array_map(fn (TaxBreakdownItem $tax) => (int) $tax->allocatedAmountCents, $this->taxBreakdown);
        }

        $weights = array_map(fn (TaxBreakdownItem $tax) => $tax->taxAmount ?? 0, $this->taxBreakdown);

        return Allocation::call($this->taxAmountCents ?? array_sum($weights), $weights);
    }
}
