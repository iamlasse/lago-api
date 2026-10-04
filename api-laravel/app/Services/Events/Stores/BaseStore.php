<?php

declare(strict_types=1);

namespace App\Services\Events\Stores;

use LogicException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Port of Rails' Events::Stores::BaseStore
 * (app/services/events/stores/base_store.rb) — the aggregation store
 * contract behind usage computation.
 *
 * The shared billing-context / boundary / filter state and the result
 * builders live here; the concrete SQL is per store
 * (PostgresStore is the active path, ClickHouseStore TODO(port)).
 *
 * Like Rails, the aggregation entry points that a concrete store does not
 * implement throw (Rails: NotImplementedError).
 */
abstract class BaseStore
{
    protected ?string $aggregationProperty = null;

    protected bool $numericProperty = false;

    protected bool $useFromBoundary = true;

    /** Rails: `attr_accessor :grouped_by` — the group keys for grouped_* aggregations. */
    protected array $groupedBy;

    /** @var array<string, mixed>|null */
    protected ?array $groupedByValues;

    protected mixed $chargeId;

    protected mixed $chargeFilterId;

    /** @var array<string, list<string>> */
    protected array $matchingFilters;

    /** @var list<array<string, list<string>>> */
    protected array $ignoredFilters;

    /** Rails: `filters[:event]` — the pay-in-advance event being aggregated. */
    protected mixed $event;

    /**
     * @param  array<string, mixed>  $filters  Rails filters hash: grouped_by,
     *                                         grouped_by_values, charge_id, charge_filter,
     *                                         matching_filters, ignored_filters, event,
     *                                         grouped_by_values, presentation_by
     */
    public function __construct(
        protected mixed $billingContext,
        protected array $boundaries = [],
        protected ?string $code = null,
        protected array $filters = [],
        protected bool $deduplicate = false,
    ) {
        $this->groupedBy = $filters['grouped_by'] ?? [];
        $this->groupedByValues = $filters['grouped_by_values'] ?? null;

        $this->chargeId = $filters['charge_id'] ?? null;
        $this->chargeFilterId = $filters['charge_filter']['id'] ?? $filters['charge_filter']?->id ?? null;
        $this->matchingFilters = $filters['matching_filters'] ?? [];
        $this->ignoredFilters = $filters['ignored_filters'] ?? [];
        $this->event = $filters['event'] ?? null;
    }

    public function groupedByValuesExists(): bool
    {
        return $this->groupedByValues !== null && $this->groupedByValues !== [];
    }

    public function precomputed(): bool
    {
        return false;
    }

    /**
     * Port of `with_grouped_by_values` — temporarily scopes the store to a
     * set of grouped_by values for the duration of the callback.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withGroupedByValues(?array $groupedByValues, callable $callback): mixed
    {
        $previous = $this->groupedByValues;

        if ($groupedByValues === null) {
            return $callback();
        }

        $this->groupedByValues = $groupedByValues;

        try {
            return $callback();
        } finally {
            $this->groupedByValues = $previous;
        }
    }

    /**
     * Port of `for_window` — mints a sibling store for another window,
     * aggregating the same property. `useFromBoundary` is left to the caller:
     * a window with no lower bound must not apply one.
     */
    public function forWindow(?array $filters = null, array $boundaries = []): static
    {
        $store = new static(
            billingContext: $this->billingContext,
            boundaries: $boundaries,
            code: $this->code,
            filters: $filters ?? $this->filters,
            deduplicate: $this->deduplicate,
        );

        $store->aggregationProperty = $this->aggregationProperty;
        $store->numericProperty = $this->numericProperty;

        return $store;
    }

    /** Rails: `attr_accessor` writes. */
    public function setAggregationProperty(?string $property): void
    {
        $this->aggregationProperty = $property;
    }

    public function setNumericProperty(bool $numeric): void
    {
        $this->numericProperty = $numeric;
    }

    public function setUseFromBoundary(bool $useFromBoundary): void
    {
        $this->useFromBoundary = $useFromBoundary;
    }

    public function setGroupedBy(array $groupedBy): void
    {
        $this->groupedBy = $groupedBy;
    }

    public function groupedBy(): array
    {
        return $this->groupedBy;
    }

    public function groupedByValues(): ?array
    {
        return $this->groupedByValues;
    }

    public function chargesDuration(): mixed
    {
        return $this->boundaries['charges_duration'] ?? null;
    }

    public function customer(): mixed
    {
        return $this->billingContext->customer();
    }

    // -- Aggregation API (Rails: NotImplementedError when unimplemented) -------

    /** @return Builder<\App\Models\Event> */
    public function events(bool $forceFrom = false, bool $ordered = false)
    {
        $this->notImplemented();
    }

    public function eventsValues(?int $limit = null, bool $forceFrom = false, bool $excludeEvent = false)
    {
        $this->notImplemented();
    }

    public function lastEvent()
    {
        $this->notImplemented();
    }

    public function count()
    {
        $this->notImplemented();
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedCount()
    {
        $this->notImplemented();
    }

    public function max(bool $withCount = true)
    {
        $this->notImplemented();
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedMax()
    {
        $this->notImplemented();
    }

    public function last(bool $withCount = true)
    {
        $this->notImplemented();
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedLast()
    {
        $this->notImplemented();
    }

    public function sum(bool $withCount = true)
    {
        $this->notImplemented();
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedSum()
    {
        $this->notImplemented();
    }

    public function sumPreciseTotalAmountCents()
    {
        $this->notImplemented();
    }

    public function groupedSumPreciseTotalAmountCents()
    {
        $this->notImplemented();
    }

    public function proratedSum(int|string $periodDuration, int|string|null $persistedDuration = null)
    {
        $this->notImplemented();
    }

    /** @return list<GroupedProratedAggregationResult> */
    public function groupedProratedSum(int|string $periodDuration, int|string|null $persistedDuration = null)
    {
        $this->notImplemented();
    }

    /** @return list<array{date: string, value: string}> */
    public function sumDateBreakdown()
    {
        $this->notImplemented();
    }

    public function weightedSum(string|int|float|null $initialValue = 0)
    {
        $this->notImplemented();
    }

    /** @return list<GroupedWeightedAggregationResult> */
    public function groupedWeightedSum(array $initialValues = [])
    {
        $this->notImplemented();
    }

    public function uniqueCount()
    {
        $this->notImplemented();
    }

    public function proratedUniqueCount()
    {
        $this->notImplemented();
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedUniqueCount()
    {
        $this->notImplemented();
    }

    /** @return list<GroupedAggregationResult> */
    public function groupedProratedUniqueCount()
    {
        $this->notImplemented();
    }

    public function activeUniqueProperty(\App\Models\Event $event)
    {
        $this->notImplemented();
    }

    public function proratedEventsValues(int|string $totalDuration)
    {
        $this->notImplemented();
    }

    /** BigDecimal subtraction: result scale = max(operand scales), as in Ruby. */
    protected static function bigDecimalSub(string $a, string $b): string
    {
        $scaleOf = fn (string $v): int => str_contains($v, '.') ? mb_strlen(mb_substr($v, mb_strrpos($v, '.') + 1)) : 0;

        return bcsub($a, $b, max($scaleOf($a), $scaleOf($b)));
    }

    /** Rails' `.presence&.to_i`. */
    protected static function presenceToInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    // -- Boundaries -----------------------------------------------------------

    /** Rails: `boundaries[:from_datetime]&.to_time&.floor(3)` — floor to milliseconds. */
    protected function fromDatetime(): mixed
    {
        $fromDatetime = $this->boundaries['from_datetime'] ?? null;

        if ($fromDatetime === null) {
            return null;
        }

        if (! $fromDatetime instanceof \Carbon\CarbonInterface) {
            return $fromDatetime;
        }

        $datetime = $fromDatetime->copy();

        return $datetime->microsecond((int) (floor($datetime->microsecond / 1000) * 1000));
    }

    /** Rails: `boundaries[:to_datetime]`. */
    protected function toDatetime(): mixed
    {
        return $this->boundaries['to_datetime'] ?? null;
    }

    /** Rails: `boundaries[:max_timestamp] || to_datetime`. */
    protected function applicableToDatetime(): mixed
    {
        return $this->boundaries['max_timestamp'] ?? $this->toDatetime();
    }

    protected function notImplemented(): never
    {
        // Rails: `raise NotImplementedError`.
        throw new LogicException(static::class.' does not implement this aggregation entry point');
    }

    // -- Result builders (Rails: BaseStore protected helpers) ------------------

    /** Port of `build_aggregation_result`. */
    protected function buildAggregationResult(?array $row): AggregationResult
    {
        return new AggregationResult(
            value: $row['value'] ?? 0,
            eventsCount: self::presenceToInt($row['events_count'] ?? null),
        );
    }

    /**
     * Port of `build_last_aggregation_result` — preserves a nil value (no
     * event or no value on the last event) instead of defaulting to 0, and
     * tolerates an empty row (LIMIT 1 → events_count 0).
     */
    protected function buildLastAggregationResult(?array $row, bool $withCount = true): AggregationResult
    {
        return new AggregationResult(
            value: $row === null ? null : ($row['value'] ?? null),
            eventsCount: $withCount ? (int) (($row['events_count'] ?? null) ?? 0) : null,
        );
    }

    /** Port of `build_prorated_aggregation_result`. */
    protected function buildProratedAggregationResult(?array $row): ProratedAggregationResult
    {
        return new ProratedAggregationResult(
            value: $row['value'] ?? 0,
            proratedValue: $row['prorated_value'] ?? 0,
            eventsCount: self::presenceToInt($row['events_count'] ?? null),
        );
    }

    /** Port of `build_grouped_prorated_aggregation_result`. */
    protected function buildGroupedProratedAggregationResult(
        array $groups,
        string|int|null $value,
        string|int|null $proratedValue,
        string|int|null $eventsCount,
    ): GroupedProratedAggregationResult {
        return new GroupedProratedAggregationResult(
            groups: $groups,
            value: $value ?? 0,
            proratedValue: $proratedValue ?? 0,
            eventsCount: self::presenceToInt($eventsCount),
        );
    }

    /**
     * Port of `build_aggregation_result_from_value` — for aggregations whose
     * value already represents the number of aggregated events (count,
     * unique_count); the value is reused as the events_count.
     */
    protected function buildAggregationResultFromValue(string|int|null $value): AggregationResult
    {
        $value = $value ?? 0;

        return new AggregationResult(value: $value, eventsCount: $value);
    }

    /**
     * Port of `grouped_results_with_value_as_count` — wraps
     * { groups:, value: } hashes into GroupedAggregationResult, reusing each
     * value as its events_count.
     *
     * @param  list<array{groups: array<string, mixed>, value: mixed}>  $rows
     * @return list<GroupedAggregationResult>
     */
    protected function groupedResultsWithValueAsCount(array $rows): array
    {
        return array_map(
            fn (array $row) => new GroupedAggregationResult(
                groups: $row['groups'],
                value: $row['value'],
                eventsCount: $row['value'],
            ),
            $rows,
        );
    }

    /** Port of `build_weighted_aggregation_result`. */
    protected function buildWeightedAggregationResult(
        string|int $value,
        string|int $variationWithInitial,
        int $rowsCount,
        string|int|float|null $initialValue,
    ): WeightedAggregationResult {
        return new WeightedAggregationResult(
            value: $value,
            // Subtract initial value from variation — BigDecimal subtraction
            // keeps the larger operand scale (no forced MoneyMath scale).
            variation: self::bigDecimalSub((string) $variationWithInitial, (string) ($initialValue ?? 0)),
            eventsCount: max($rowsCount - 2, 0), // Handle zero duration case
        );
    }

    /** Port of `build_grouped_weighted_result`. */
    protected function buildGroupedWeightedResult(
        array $groups,
        string|int $value,
        string|int|null $variationWithInitial,
        int|string $rowsCount,
        array $initialValues,
    ): GroupedWeightedAggregationResult {
        $initialValue = 0;
        foreach ($initialValues as $iv) {
            if ($iv['groups'] === $groups) {
                $initialValue = $iv['value'] ?? 0;

                break;
            }
        }

        $weighted = $this->buildWeightedAggregationResult(
            value: $value,
            variationWithInitial: $variationWithInitial ?? 0,
            rowsCount: (int) $rowsCount,
            initialValue: $initialValue,
        );

        return new GroupedWeightedAggregationResult(
            groups: $groups,
            value: $weighted->value,
            variation: $weighted->variation,
            eventsCount: $weighted->eventsCount,
        );
    }

    /**
     * Port of `build_groups` — the { column => value } groups hash used by
     * grouped aggregation results.
     *
     * @param  list<mixed>  $values
     * @return array<string, mixed>
     */
    protected function buildGroups(array $values, ?array $columns = null): array
    {
        $columns ??= $this->groupedBy;

        $groups = [];
        foreach (array_values($columns) as $index => $column) {
            $value = $values[$index] ?? null;

            $groups[$column] = ($value === null || $value === '') ? null : $value;
        }

        return $groups;
    }
}
