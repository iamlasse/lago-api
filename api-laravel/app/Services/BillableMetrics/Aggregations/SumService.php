<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use App\Services\ChargeModels\AggregationResult;

/**
 * Port of Rails' BillableMetrics::Aggregations::SumService
 * (app/services/billable_metrics/aggregations/sum_service.rb).
 *
 * TODO(port): the grouped_by / presentation_by branches (M2 filters
 * pipeline) and the per-event aggregation for pay-in-advance events.
 */
final class SumService extends BaseService
{
    public function __construct(
        \App\Services\Events\Stores\BaseStore $eventStore,
        \App\Services\Fees\ChargeService\MeteredItem $meteredItem,
        \App\Models\Billing\Context $billingContext,
        array $boundaries,
        array $filters = [],
        bool $bypassAggregation = false,
        array $aggregationOptions = [],
    ) {
        parent::__construct($eventStore, $meteredItem, $billingContext, $boundaries, $filters, $bypassAggregation, $aggregationOptions);

        $eventStore->setNumericProperty(true);
        $eventStore->setAggregationProperty($this->billableMetric()->field_name);
        $eventStore->setUseFromBoundary(! $this->billableMetric()->recurring);
    }

    public function computeAggregation(): AggregationResult
    {
        if ($this->shouldBypassAggregation()) {
            return $this->nullResult();
        }

        $sumResult = $this->eventStore->sum();

        $aggregation = (string) $sumResult->value;
        $currentUsageUnits = null;

        // Rails: `options[:is_pay_in_advance] && options[:is_current_usage]` —
        // the ONLY path that reads cached_aggregations here. Periodic
        // in-arrears billing aggregates the events live.
        if ($this->options()['is_pay_in_advance'] && $this->options()['is_current_usage']) {
            $adjusted = $this->handleInAdvanceCurrentUsage($aggregation);
            $aggregation = $adjusted->aggregation;
            $currentUsageUnits = $adjusted->currentUsageUnits;
        }

        return new AggregationResult(
            aggregation: $aggregation,
            currentUsageUnits: $currentUsageUnits,
            count: (int) ($sumResult->eventsCount ?? 0),
            options: ['running_total' => $this->runningTotal()],
        );
    }

    /**
     * Port of `running_total` — the cumulative sum of the field's values
     * limited by the number of free units (percentage charges' free units).
     *
     * @return list<string>
     */
    protected function runningTotal(): array
    {
        $options = $this->options();
        $freeUnitsPerEvents = (int) ($options['free_units_per_events'] ?? 0);
        $freeUnitsPerTotalAggregation = (string) ($options['free_units_per_total_aggregation'] ?? '0');

        if ($freeUnitsPerEvents === 0 && \App\Support\MoneyMath::compare($freeUnitsPerTotalAggregation, '0') === 0) {
            return [];
        }

        if ($freeUnitsPerEvents !== 0) {
            return $this->runningTotalPerEvents($freeUnitsPerEvents);
        }

        return $this->runningTotalPerAggregation($freeUnitsPerTotalAggregation);
    }

    /** Port of `running_total_per_events(limit)`. */
    protected function runningTotalPerEvents(int $limit): array
    {
        $values = $this->eventStore->eventsValues(limit: $limit);

        $total = '0';
        $result = [];
        foreach ($values as $value) {
            $total = \App\Support\MoneyMath::add($total, (string) $value);
            $result[] = $total;
        }

        return $result;
    }

    /** Port of `running_total_per_aggregation(aggregation)`. */
    protected function runningTotalPerAggregation(string $aggregation): array
    {
        $values = $this->eventStore->eventsValues();

        $total = '0';
        $result = [];
        foreach ($values as $value) {
            if (\App\Support\MoneyMath::compare($aggregation, $total) < 0) {
                break;
            }

            $total = \App\Support\MoneyMath::add($total, (string) $value);
            $result[] = $total;
        }

        return $result;
    }

    /** Rails: `options` passed to `#aggregate` — inherited from BaseService. */
}
