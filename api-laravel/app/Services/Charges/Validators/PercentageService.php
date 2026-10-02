<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators;

use App\Services\Validators\DecimalAmount;
use App\Services\Charges\AggregationChecks;

/**
 * Port of Rails' Charges::Validators::PercentageService.
 */
class PercentageService extends BaseService
{
    public function valid(): bool
    {
        $this->validateBillableMetric();
        $this->validateRate();
        $this->validateFixedAmount();
        $this->validateFreeUnitsPerEvents();
        $this->validateFreeUnitsPerTotalAggregation();
        $this->validatePerTransactionMinMax();

        return parent::valid();
    }

    private function rate(): mixed
    {
        return $this->properties['rate'] ?? null;
    }

    private function validateBillableMetric(): void
    {
        $billableMetric = $this->charge instanceof \App\Models\Charge
            ? $this->charge->billableMetric
            : null;

        // Only `latest` aggregation is valid for percentage charges.
        if (! AggregationChecks::isLatest($billableMetric)) {
            $this->addError('billable_metric', 'invalid_value');
        }
    }

    private function validateRate(): void
    {
        if (! DecimalAmount::validAmount($this->rate())) {
            $this->addError('rate', 'invalid_rate');
        }
    }

    private function validateFixedAmount(): void
    {
        $fixedAmount = $this->properties['fixed_amount'] ?? null;

        if ($fixedAmount === null) {
            return;
        }

        if (! DecimalAmount::validAmount($fixedAmount)) {
            $this->addError('fixed_amount', 'invalid_fixed_amount');
        }
    }

    private function validateFreeUnitsPerEvents(): void
    {
        $freeUnitsPerEvents = $this->properties['free_units_per_events'] ?? null;

        if ($freeUnitsPerEvents === null) {
            return;
        }

        if (is_int($freeUnitsPerEvents) && $freeUnitsPerEvents > 0) {
            return;
        }

        $this->addError('free_units_per_events', 'invalid_free_units_per_events');
    }

    private function validateFreeUnitsPerTotalAggregation(): void
    {
        $freeUnitsPerTotalAggregation = $this->properties['free_units_per_total_aggregation'] ?? null;

        if ($freeUnitsPerTotalAggregation === null) {
            return;
        }

        if (! DecimalAmount::validAmount($freeUnitsPerTotalAggregation)) {
            $this->addError('free_units_per_total_aggregation', 'invalid_free_units_per_total_aggregation');
        }
    }

    private function validatePerTransactionMinMax(): void
    {
        if (! $this->premium()) {
            return;
        }

        $min = $this->properties['per_transaction_min_amount'] ?? null;
        $max = $this->properties['per_transaction_max_amount'] ?? null;

        if (($min ?? null) !== null && $min !== '' && ! DecimalAmount::validAmount($min)) {
            $this->addError('per_transaction_min_amount', 'invalid_amount');
        }

        if (($max ?? null) !== null && $max !== '' && ! DecimalAmount::validAmount($max)) {
            $this->addError('per_transaction_max_amount', 'invalid_amount');
        }

        if ($min === null || $max === null) {
            return;
        }

        $minDecimal = DecimalAmount::canonical($min);
        $maxDecimal = DecimalAmount::canonical($max);

        if ($minDecimal === null || $maxDecimal === null) {
            return;
        }

        if (bccomp($minDecimal, $maxDecimal, 20) !== 1) {
            return;
        }

        $this->addError('per_transaction_max_amount', 'per_transaction_max_lower_than_per_transaction_min');
    }
}
