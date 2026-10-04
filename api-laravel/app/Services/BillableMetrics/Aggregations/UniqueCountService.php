<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use App\Support\MoneyMath;
use App\Services\ChargeModels\AggregationResult;

/**
 * Port of Rails' BillableMetrics::Aggregations::UniqueCountService
 * (app/services/billable_metrics/aggregations/unique_count_service.rb).
 *
 * TODO(port): the pay-in-advance per-event branches (active_unique_property?,
 * compute_pay_in_advance_aggregation, per-event aggregation) and the
 * grouped_by / presentation_by branches (M2).
 */
final class UniqueCountService extends BaseService
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

        $eventStore->setAggregationProperty($this->billableMetric()->field_name);
        $eventStore->setUseFromBoundary(! $this->billableMetric()->recurring);
    }

    public function computeAggregation(): AggregationResult
    {
        if ($this->shouldBypassAggregation()) {
            return $this->nullResult();
        }

        $aggregation = $this->ceilTo5((string) $this->eventStore->uniqueCount()->value);

        $currentUsageUnits = null;
        if ($this->options()['is_pay_in_advance'] && $this->options()['is_current_usage']) {
            $adjusted = $this->handleInAdvanceCurrentUsage($aggregation);
            $aggregation = $adjusted->aggregation;
            $currentUsageUnits = $adjusted->currentUsageUnits;
        }

        return new AggregationResult(
            aggregation: $aggregation,
            currentUsageUnits: $currentUsageUnits,
            count: (int) $aggregation,
            options: ['running_total' => $this->runningTotal($aggregation)],
        );
    }

    /**
     * Port of UniqueCountService#running_total — the cumulative count up to
     * the number of free units.
     *
     * @return list<int>
     */
    protected function runningTotal(string $aggregation): array
    {
        $options = $this->options();
        $freeUnitsPerEvents = (int) ($options['free_units_per_events'] ?? 0);
        $freeUnitsPerTotalAggregation = (string) ($options['free_units_per_total_aggregation'] ?? '0');

        if ($freeUnitsPerEvents === 0 && MoneyMath::compare($freeUnitsPerTotalAggregation, '0') === 0) {
            return [];
        }

        return range(1, (int) $aggregation);
    }

    /** Rails: `aggregation.ceil(5)`. */
    private function ceilTo5(string $value): string
    {
        $scaled = bcmul($value, '100000');
        $truncated = bcadd($scaled, '0', 0);

        $ceiled = MoneyMath::compare($scaled, $truncated) > 0
            ? bcadd($truncated, '1', 0)
            : $truncated;

        return bcdiv($ceiled, '100000', 5);
    }
}
