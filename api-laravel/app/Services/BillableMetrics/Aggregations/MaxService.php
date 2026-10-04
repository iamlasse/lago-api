<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use App\Services\ChargeModels\AggregationResult;

/**
 * Port of Rails' BillableMetrics::Aggregations::MaxService.
 *
 * TODO(port): grouped_by / presentation_by branches (M2).
 */
final class MaxService extends BaseService
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

        $maxResult = $this->eventStore->max();

        return new AggregationResult(
            aggregation: (string) $maxResult->value,
            count: (int) ($maxResult->eventsCount ?? 0),
            options: $this->options(),
        );
    }
}
