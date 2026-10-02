<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

use App\Support\MoneyMath;

/**
 * Port of Rails' ChargeModels::PercentageService.
 *
 * TODO(port): per-transaction min/max applies only with a premium license
 * and needs per-event aggregation values (aggregation_result.aggregator) —
 * both arrive with the M2 event store. Until then min/max properties are
 * ignored, matching the non-premium Rails behavior.
 */
class PercentageService extends AbstractChargeModel
{
    protected function computeAmount(): string
    {
        return MoneyMath::add($this->computePercentageAmount(), $this->computeFixedAmount());
    }

    protected function computeProjectedAmount(): string
    {
        return $this->projectedAmountFromCurrentAmount();
    }

    /** @return array<string, mixed> */
    protected function amountDetails(): array
    {
        $units = $this->units();
        $paidUnits = MoneyMath::sub($units, $this->freeUnitsValue());

        if (MoneyMath::compare($paidUnits, '0') < 0) {
            $paidUnits = '0';
        }

        $perUnitTotal = MoneyMath::compare($paidUnits, '0') === 0
            ? '0'
            : MoneyMath::fdiv($this->computePercentageAmount(), $paidUnits);

        $count = $this->aggregationResult->count ?? 0;
        $freeEvents = min($count, $this->freeUnitsCount());
        $paidEvents = $count - $freeEvents;
        $fixedAmount = $this->fixedAmount();

        $minMaxAdjustment = '0'; // TODO(port): premium per-transaction min/max (M2).

        return [
            'units' => $units,
            'free_units' => $this->freeUnitsValue(),
            'free_events' => $freeEvents,
            'paid_units' => $paidUnits,
            'rate' => $this->rate(),
            'per_unit_total_amount' => $perUnitTotal,
            'paid_events' => $paidEvents,
            'fixed_fee_unit_amount' => (MoneyMath::compare($paidUnits, '0') > 0 || $paidEvents > 0) ? $fixedAmount : '0',
            'fixed_fee_total_amount' => $this->computeFixedAmount(),
            'min_max_adjustment_total_amount' => $minMaxAdjustment,
        ];
    }

    protected function unitAmount(): string
    {
        return $this->zeroIfNoTotalUnits();
    }

    protected function computePercentageAmount(): string
    {
        if (MoneyMath::compare($this->freeUnitsValue(), $this->units()) > 0) {
            return '0';
        }

        return MoneyMath::fdiv(
            MoneyMath::mul(MoneyMath::sub($this->units(), $this->freeUnitsValue()), $this->rate()),
            '100',
        );
    }

    protected function computeFixedAmount(): string
    {
        if (MoneyMath::compare($this->units(), '0') === 0) {
            return '0';
        }

        if ($this->properties()['fixed_amount'] === null) {
            return '0';
        }

        if ($this->freeUnitsCount() >= ($this->aggregationResult->count ?? 0)) {
            return '0';
        }

        return MoneyMath::mul(
            (string) (($this->aggregationResult->count ?? 0) - $this->freeUnitsCount()),
            $this->fixedAmount(),
        );
    }

    protected function freeUnitsValue(): string
    {
        if (MoneyMath::compare($this->lastRunningTotal(), '0') === 0) {
            return '0';
        }

        $freeUnitsPerEvents = $this->freeUnitsPerEvents();
        $runningTotal = $this->aggregationResult->options['running_total'] ?? [];

        if ($freeUnitsPerEvents > 0 && $freeUnitsPerEvents < count($runningTotal)) {
            return (string) $runningTotal[$freeUnitsPerEvents - 1];
        }

        if ($this->freeUnitsPerTotalAggregation() === '0') {
            return $this->lastRunningTotal();
        }

        if (MoneyMath::compare($this->lastRunningTotal(), $this->freeUnitsPerTotalAggregation()) <= 0) {
            return $this->lastRunningTotal();
        }

        return $this->freeUnitsPerTotalAggregation();
    }

    protected function freeUnitsCount(): int
    {
        $runningTotal = $this->aggregationResult->options['running_total'] ?? [];
        $freePerTotal = $this->freeUnitsPerTotalAggregation();

        $countBelow = 0;
        foreach ($runningTotal as $value) {
            if (MoneyMath::compare((string) $value, $freePerTotal) < 0) {
                $countBelow++;
            }
        }

        $candidates = array_values(array_filter(
            [$this->freeUnitsPerEvents(), $countBelow],
            fn (int $v) => $v !== 0,
        ));

        if ($candidates === []) {
            return 0;
        }

        return min($candidates);
    }

    protected function lastRunningTotal(): string
    {
        $runningTotal = $this->aggregationResult->options['running_total'] ?? [];

        return ($runningTotal === []) ? '0' : (string) end($runningTotal);
    }

    protected function freeUnitsPerTotalAggregation(): string
    {
        return (string) ($this->properties()['free_units_per_total_aggregation'] ?? '0');
    }

    protected function freeUnitsPerEvents(): int
    {
        return (int) ($this->properties()['free_units_per_events'] ?? 0);
    }

    /** NOTE: FE divides percentage rate with 100 and sends to BE. */
    protected function rate(): string
    {
        return (string) ($this->properties()['rate'] ?? '0');
    }

    protected function fixedAmount(): string
    {
        return (string) ($this->properties()['fixed_amount'] ?? '0');
    }
}
