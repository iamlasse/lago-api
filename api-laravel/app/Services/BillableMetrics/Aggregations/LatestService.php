<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use App\Services\ChargeModels\AggregationResult;

/**
 * Port of Rails' BillableMetrics::Aggregations::LatestService.
 *
 * TODO(port): grouped_by / presentation_by branches (M2).
 */
final class LatestService extends BaseService
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
    }

    public function computeAggregation(): AggregationResult
    {
        if ($this->shouldBypassAggregation()) {
            return $this->nullResult();
        }

        $lastResult = $this->eventStore->last();

        return new AggregationResult(
            aggregation: $this->aggregationValue($lastResult->value),
            count: $lastResult->eventsCount === null ? null : (int) $lastResult->eventsCount,
            options: $this->options(),
        );
    }

    /** Port of `compute_aggregation_value` — clamp negatives to zero. */
    protected function aggregationValue(string|int|null $latestValue): string
    {
        $value = (string) ($latestValue ?? '0');

        return \App\Support\MoneyMath::compare($value, '0') < 0 ? '0' : $value;
    }
}
