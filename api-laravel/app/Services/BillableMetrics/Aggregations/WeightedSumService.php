<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use App\Support\MoneyMath;
use App\Models\CachedAggregation;
use App\Services\ChargeModels\AggregationResult;

/**
 * Port of Rails' BillableMetrics::Aggregations::WeightedSumService
 * (app/services/billable_metrics/aggregations/weighted_sum_service.rb) —
 * the non-grouped aggregation path.
 *
 * Recurring metrics carry their value over periods: the previous total is
 * read from the latest cached_aggregations row BEFORE the period (the one
 * legitimate arrears cached read), falling back to the previous
 * subscription's events.
 *
 * TODO(port): grouped_by / presentation_by branches (M2).
 */
final class WeightedSumService extends BaseService
{
    private ?string $latestValueCache;

    private bool $latestValueResolved = false;

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

        $weightedResult = $this->eventStore->weightedSum($this->initialValue());

        $aggregation = $this->ceilTo20((string) $weightedResult->value);
        $variation = (string) $weightedResult->variation;
        $totalAggregatedUnits = $variation;

        $recurringUpdatedAt = null;
        if ($this->billableMetric()->recurring) {
            $totalAggregatedUnits = MoneyMath::add((string) $this->latestValue(), $variation);
            $lastEvent = $this->eventStore->lastEvent();
            $recurringUpdatedAt = \App\Support\Utils\Datetime::serialize(
                $lastEvent !== null ? $lastEvent->timestamp : $this->fromDatetime(),
            );
        }

        return new AggregationResult(
            aggregation: $aggregation,
            count: $weightedResult->eventsCount,
            totalAggregatedUnits: $totalAggregatedUnits,
            options: [],
            recurringUpdatedAt: $recurringUpdatedAt,
        );
    }

    /** Port of `initial_value` — 0 unless the metric is recurring. */
    protected function initialValue(): string
    {
        return $this->billableMetric()->recurring ? (string) $this->latestValue() : '0';
    }

    /**
     * Port of `latest_value` — the cached total before the period, else the
     * previous subscription's sum, else 0.
     */
    protected function latestValue(): string
    {
        if ($this->latestValueResolved) {
            return $this->latestValueCache;
        }

        $this->latestValueResolved = true;

        $cachedAggregation = $this->latestCachedAggregation();

        if ($cachedAggregation !== null) {
            return $this->latestValueCache = (string) $cachedAggregation->current_aggregation;
        }

        if ($this->billingContext->previousSubscriptionIdExists()) {
            $store = $this->eventStore->forWindow(
                boundaries: ['to_datetime' => $this->datetimeMinusASecond($this->fromDatetime())],
            );
            $store->setUseFromBoundary(false);

            return $this->latestValueCache = (string) $store->sum(withCount: false)->value;
        }

        return $this->latestValueCache = '0';
    }

    /** Port of `latest_cached_aggregation` — the latest row BEFORE from_datetime. */
    protected function latestCachedAggregation(): ?CachedAggregation
    {
        $query = CachedAggregation::query()
            ->where('organization_id', $this->billableMetric()->organization_id)
            ->where('external_subscription_id', $this->billingContext->externalId())
            ->where('charge_id', $this->meteredItem->chargeId())
            ->where('timestamp', '<', $this->fromDatetime())
            ->orderByDesc('timestamp')
            ->orderByDesc('created_at');

        if ($this->chargeFilterId() !== null) {
            $query->where('charge_filter_id', $this->chargeFilterId());
        }

        return $query->first();
    }

    private function datetimeMinusASecond(mixed $datetime): mixed
    {
        return \Carbon\Carbon::parse($datetime)->subSecond();
    }

    /** Rails: `aggregation.ceil(20)`. */
    private function ceilTo20(string $value): string
    {
        $scaled = bcmul($value, bcpow('10', '20'));
        $truncated = bcadd($scaled, '0', 0);

        $ceiled = MoneyMath::compare($scaled, $truncated) > 0
            ? bcadd($truncated, '1', 0)
            : $truncated;

        return bcdiv($ceiled, bcpow('10', '20'), 20);
    }
}
