<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use Throwable;
use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::BaseService
 * (app/services/charge_models/base_service.rb).
 *
 * Rails: `apply` copies the aggregation fields onto the result, computes
 * amount / unit_amount / amount_details, then wraps itself as a single
 * `grouped_results` entry. Amounts are decimal strings.
 */
abstract class AbstractChargeModel
{
    protected ChargeModelResult $result;

    public function __construct(
        protected readonly PricingStructure $pricingStructure,
        protected readonly AggregationResult $aggregationResult,
        protected readonly string|float|null $periodRatio = null,
        protected readonly bool $calculateProjectedUsage = false,
    ) {
        $this->result = new ChargeModelResult;
    }

    abstract protected function computeProjectedAmount(): string;

    abstract protected function computeAmount(): string;

    abstract protected function unitAmount(): string;

    public function apply(): ChargeModelResult
    {
        $this->result->units = $this->aggregationResult->units();
        $this->result->currentUsageUnits = $this->aggregationResult->currentUsageUnits;
        $this->result->fullUnitsNumber = $this->aggregationResult->fullUnitsNumber;
        $this->result->count = $this->aggregationResult->count;
        $this->result->amount = $this->computeAmount();
        $this->result->unitAmount = $this->unitAmount();
        $this->result->amountDetails = $this->amountDetails();

        if ($this->aggregationResult->totalAggregatedUnits !== null) {
            $this->result->totalAggregatedUnits = $this->aggregationResult->totalAggregatedUnits;
        }

        if ($this->calculateProjectedUsage) {
            $this->result->projectedUnits = $this->projectedUnits();
            $this->result->projectedAmount = $this->computeProjectedAmount();
        }

        $this->result->groupedResults = [$this->result];

        return $this->result;
    }

    /** Rails delegates `units` to the result. */
    protected function units(): string
    {
        return (string) $this->result->units;
    }

    /** Rails delegates `grouped_by` to the aggregation result. */
    protected function groupedBy(): array
    {
        return $this->aggregationResult->groupedBy;
    }

    protected function properties(): array
    {
        return $this->pricingStructure->properties;
    }

    /** Port of the shared `projected_units` helper. */
    protected function projectedUnits(): string
    {
        $units = (string) $this->result->units;

        if ($units === '' || MoneyMath::compare($units, '0') === 0) {
            return '0';
        }

        try {
            if ($this->periodRatio !== null && MoneyMath::compare((string) $this->periodRatio, '0') > 0) {
                // Ruby `.round(2)` — halves away from zero at 2 decimals.
                return MoneyMath::roundTo(MoneyMath::fdiv($units, (string) $this->periodRatio), 2);
            }

            return '0';
        } catch (Throwable) {
            return '0';
        }
    }

    /** @return array<string, mixed> */
    protected function amountDetails(): array
    {
        return [];
    }

    // -- Shared helpers -------------------------------------------------------

    /** Port of the common `unit_amount` denominator: full units or units. */
    protected function unitAmountDenominator(): string
    {
        return $this->aggregationResult->fullUnitsNumber ?? $this->units();
    }

    /** Port of the common `unit_amount` when the total is zero. */
    protected function zeroIfNoTotalUnits(): string
    {
        $totalUnits = $this->unitAmountDenominator();

        return MoneyMath::compare($totalUnits, '0') === 0
            ? '0'
            : MoneyMath::fdiv((string) $this->result->amount, $totalUnits);
    }

    /**
     * Port of `compute_projected_amount` as implemented by percentage,
     * custom, dynamic and prorated graduated models: current amount
     * extrapolated over the full period.
     */
    protected function projectedAmountFromCurrentAmount(): string
    {
        $currentAmount = (string) $this->result->amount;

        if (MoneyMath::compare($currentAmount, '0') === 0
            || $this->periodRatio === null
            || MoneyMath::compare((string) $this->periodRatio, '0') === 0) {
            return '0';
        }

        return MoneyMath::fdiv($currentAmount, (string) $this->periodRatio);
    }
}
