<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use App\Services\ChargeModels\AggregationResult;

/**
 * Port of Rails' BillableMetrics::Aggregations::CountService.
 *
 * TODO(port): grouped_by / presentation_by branches (M2).
 */
final class CountService extends BaseService
{
    public function computeAggregation(): AggregationResult
    {
        if ($this->shouldBypassAggregation()) {
            return $this->nullResult();
        }

        $countResult = $this->eventStore->count();
        $aggregation = (string) $countResult->value;

        return new AggregationResult(
            aggregation: $aggregation,
            currentUsageUnits: $aggregation,
            count: (int) ($countResult->eventsCount ?? 0),
            options: ['running_total' => $this->runningTotal($aggregation)],
        );
    }

    /**
     * Port of CountService#running_total — the cumulative event count up to
     * the number of free units.
     *
     * @return list<int>
     */
    protected function runningTotal(string $aggregation): array
    {
        $options = $this->options();
        $freeUnitsPerEvents = (int) ($options['free_units_per_events'] ?? 0);
        $freeUnitsPerTotalAggregation = (string) ($options['free_units_per_total_aggregation'] ?? '0');

        if ($freeUnitsPerEvents === 0 && \App\Support\MoneyMath::compare($freeUnitsPerTotalAggregation, '0') === 0) {
            return [];
        }

        return range(1, (int) $aggregation);
    }
}
