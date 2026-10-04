<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use LogicException;
use App\Models\CachedAggregation;
use App\Services\Events\Stores\BaseStore;
use App\Services\ChargeModels\AggregationResult;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Models\Billing\Context as BillingContext;

/**
 * Port of Rails' BillableMetrics::Aggregations::BaseService
 * (app/services/billable_metrics/aggregations/base_service.rb) — the
 * aggregation contract behind Fees::ChargeService.
 *
 * The result object is the charge-model contract
 * (ChargeModels\AggregationResult) — Rails' BaseService::Result fields map
 * onto it (aggregation, count, current_usage_units, options.running_total,
 * total_aggregated_units, recurring_updated_at, ...).
 *
 * TODO(port): the grouped_by / presentation_by branches (charge filters and
 * pricing group keys arrive with the M2 filters pipeline) and the
 * per-event aggregation used by estimate_fees / pay-in-advance events.
 */
abstract class BaseService
{
    public function __construct(
        protected readonly BaseStore $eventStore,
        protected readonly MeteredItem $meteredItem,
        protected readonly BillingContext $billingContext,
        /** Rails boundaries: from_datetime, to_datetime, charges_duration, max_timestamp. */
        protected readonly array $boundaries,
        protected readonly array $filters = [],
        protected readonly bool $bypassAggregation = false,
        /** Rails: the `options` hash handed to `#aggregate(options:)`. */
        protected readonly array $aggregationOptions = [],
    ) {}

    abstract public function computeAggregation(): AggregationResult;

    /**
     * Port of `self.null_result` — the zero aggregation.
     */
    public static function nullResult(?array $groupedByKeys = null): AggregationResult
    {
        return new AggregationResult(
            aggregation: '0',
            count: 0,
            currentUsageUnits: '0',
            groupedBy: $groupedByKeys !== null
                ? array_combine($groupedByKeys, array_fill(0, count($groupedByKeys), null))
                : [],
            options: ['running_total' => []],
        );
    }

    /** Port of `empty_results` — a null result downstream charge models can dispatch through. */
    public function emptyResults(): AggregationResult
    {
        return static::nullResult($this->groupedBy());
    }

    /** Port of `#aggregate` (non-grouped path; grouped TODO(port)). */
    public function aggregate(): AggregationResult
    {
        if ($this->groupedBy() !== []) {
            // Rails: compute_grouped_by_aggregation — charge filters / pricing
            // group keys pipeline (M2).
            throw new LogicException('Grouped aggregation is not ported yet (filters pipeline, M2) — TODO(port)');
        }

        $result = $this->computeAggregation();

        return $this->applyRounding($result);
    }

    // -- Shared state helpers ---------------------------------------------------

    public function eventStore(): BaseStore
    {
        return $this->eventStore;
    }

    /** Rails: `billable_metric` (delegated from metered_item). */
    public function billableMetric(): \App\Models\BillableMetric
    {
        return $this->meteredItem->billableMetric();
    }

    /** Rails: `options` — the aggregation options (free units, pay in advance flags). */
    protected function options(): array
    {
        return $this->aggregationOptions;
    }

    /** @return list<string> */
    protected function groupedBy(): array
    {
        return $this->filters['grouped_by'] ?? [];
    }

    protected function fromDatetime(): mixed
    {
        return $this->boundaries['from_datetime'] ?? null;
    }

    protected function toDatetime(): mixed
    {
        return $this->boundaries['to_datetime'] ?? null;
    }

    /** Port of `should_bypass_aggregation?`. */
    protected function shouldBypassAggregation(): bool
    {
        if ($this->billableMetric()->recurring) {
            return false;
        }

        if ($this->eventStore->precomputed()) {
            return false;
        }

        return $this->bypassAggregation;
    }

    /**
     * Port of `find_cached_aggregation` — the latest cached aggregation in
     * the current period (used by the pay-in-advance current usage paths).
     */
    protected function findCachedAggregation(mixed $withFromDatetime, mixed $withToDatetime, ?array $groupedBy = null): ?CachedAggregation
    {
        $from = \Carbon\Carbon::parse($withFromDatetime);
        $from->microsecond = 0;
        $to = \Carbon\Carbon::parse($withToDatetime);
        $to->microsecond = 0;

        // NOTE: second-precision column comparison — the model bindings
        // truncate microseconds, so a raw `<=` would silently exclude a
        // cached row written in the same second as the boundary.

        $query = CachedAggregation::query()
            ->where('organization_id', $this->billableMetric()->organization_id)
            ->where('external_subscription_id', $this->billingContext->externalId())
            ->where('charge_id', $this->meteredItem->chargeId())
            ->where('timestamp', '>=', $from)
            ->whereRaw('date_trunc(\'second\', timestamp) <= ?::timestamp', [$to])
            // BUGFIX(port): an array binding never matches the jsonb column
            // (the [] is bound as an empty string) — bind the encoded JSON
            // string instead.
            ->where('grouped_by', json_encode($groupedBy !== null && $groupedBy !== [] ? $groupedBy : []))
            ->orderByDesc('timestamp')
            ->orderByDesc('created_at');

        // Rails excludes the current in-flight event's cached row; no event
        // filter exists on this path yet.
        if ($this->chargeFilterId() !== null) {
            $query->where('charge_filter_id', $this->chargeFilterId());
        }

        return $query->first();
    }

    protected function chargeFilterId(): mixed
    {
        return $this->filters['charge_filter']['id'] ?? $this->filters['charge_filter']?->id ?? null;
    }

    /**
     * Port of `handle_in_advance_current_usage` — the cached-row adjustment
     * of the pay-in-advance current usage path (the ONLY arrears-independent
     * cached_aggregations read: periodic in-arrears billing never touches
     * cached rows).
     */
    protected function handleInAdvanceCurrentUsage(string $totalAggregation): AggregationResult
    {
        $cachedAggregation = $this->findCachedAggregation(
            $this->fromDatetime(),
            $this->toDatetime(),
        );

        $aggregation = $cachedAggregation === null
            ? $totalAggregation
            // NOTE: re-normalized — bcadd/bcsub keep the fixed 15-decimal
            // scale, while the raw store aggregation stays trimmed.
            : \App\Support\MoneyMath::toDecimalString(\App\Support\MoneyMath::add(
                \App\Support\MoneyMath::sub($totalAggregation, (string) $cachedAggregation->current_aggregation),
                (string) $cachedAggregation->max_aggregation,
            ));

        if (\App\Support\MoneyMath::compare($aggregation, '0') < 0) {
            $aggregation = '0';
        }

        return new AggregationResult(aggregation: $aggregation, currentUsageUnits: $totalAggregation);
    }

    /** Port of `apply_rounding` — the metric's rounding function. */
    protected function applyRounding(AggregationResult $result): AggregationResult
    {
        $metric = $this->billableMetric();

        if ($metric->rounding_function === null) {
            return $result;
        }

        $apply = fn (?string $units): ?string => $units === null
            ? null
            : ApplyRoundingService::units($metric, $units);

        return new AggregationResult(
            aggregation: $apply($result->aggregation),
            currentUsageUnits: $apply($result->currentUsageUnits),
            fullUnitsNumber: $apply($result->fullUnitsNumber),
            count: $result->count,
            totalAggregatedUnits: $result->totalAggregatedUnits,
            customAggregation: $result->customAggregation,
            groupedBy: $result->groupedBy,
            aggregations: $result->aggregations,
            options: $result->options,
            recurringUpdatedAt: $result->recurringUpdatedAt,
            preciseTotalAmountCents: $result->preciseTotalAmountCents,
        );
    }
}
