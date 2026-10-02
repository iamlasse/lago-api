<?php

declare(strict_types=1);

namespace App\Services\ChargeModels;

/**
 * Port of Rails' aggregation result contract (the value returned by
 * BillableMetrics::Aggregations::*Service#aggregate).
 *
 * M1 seam: events / ClickHouse aggregation is NOT ported (M2). The invoice
 * pipeline consumes this value object; `CachedAggregationProvider` builds it
 * from the frozen `cached_aggregations` table (recurring metrics) and tests
 * seed it directly.
 *
 * TODO(port): live event aggregation (BillableMetrics::Aggregations::*,
 * Events::Stores) will produce instances of this same object in M2.
 */
final class AggregationResult
{
    /**
     * @param  string|null  $aggregation  the aggregated units (decimal string)
     * @param  string|null  $currentUsageUnits  units for current usage (prorated / in-advance charges)
     * @param  string|null  $fullUnitsNumber  total units ignoring proration
     * @param  int|null  $count  number of aggregated events
     * @param  string|null  $totalAggregatedUnits  weighted-sum total
     * @param  array{amount?: string}|null  $customAggregation  custom aggregation payload
     * @param  array<string, mixed>  $groupedBy  group keys applied on event properties
     * @param  list<self>|null  $aggregations  per-group results (grouped aggregation)
     * @param  array{running_total?: list<string>}|null  $options  aggregation options (percentage free units)
     * @param  string|null  $recurringUpdatedAt  timestamp the recurring value was last updated at
     * @param  string|null  $preciseTotalAmountCents  dynamic model: precise event total (cents)
     */
    public function __construct(
        public readonly ?string $aggregation = null,
        public readonly ?string $currentUsageUnits = null,
        public readonly ?string $fullUnitsNumber = null,
        public readonly ?int $count = null,
        public readonly ?string $totalAggregatedUnits = null,
        public readonly ?array $customAggregation = null,
        public readonly array $groupedBy = [],
        public readonly ?array $aggregations = null,
        public readonly ?array $options = null,
        public readonly ?string $recurringUpdatedAt = null,
        public readonly ?string $preciseTotalAmountCents = null,
    ) {}

    /** Zero-unit result — port of the aggregations' `empty_results`. */
    public static function empty(): self
    {
        return new self(aggregation: '0', count: 0);
    }

    public function units(): string
    {
        return $this->aggregation ?? '0';
    }
}
