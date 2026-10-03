<?php

declare(strict_types=1);

namespace App\Services\Fees\ChargeService;

use App\Models\CachedAggregation;
use App\Services\ChargeModels\AggregationResult;

/**
 * The M1 aggregation seam.
 *
 * Rails resolves a BillableMetrics::AggregationFactory service that queries
 * the events store (ClickHouse in production Rails) per charge / period.
 * Events are NOT ported (M2): this provider builds the same
 * AggregationResult contract from pre-aggregated state —
 * the frozen `cached_aggregations` rows written for recurring metrics —
 * and from values handed in by callers/tests.
 *
 * TODO(port): live event aggregation (BillableMetrics::Aggregations::*,
 * Events::Stores::*, Events::BillingPeriodFilterService) replaces the
 * cached-row lookup in M2.
 */
final class Aggregator
{
    public function __construct(
        private readonly MeteredItem $meteredItem,
        private readonly string $externalSubscriptionId,
    ) {}

    public function aggregate(): AggregationResult
    {
        $cached = $this->latestCachedAggregation();

        // TODO(port): correct per-aggregation-type semantics (max aggregation,
        // unique count carry-over, weighted sum breakdowns, custom scripts)
        // arrive with the M2 event store. Until then the latest cached value
        // is the pre-aggregated units contract.
        //
        // CONTRACT GAP (documented in scripts/contract/README.md findings 9/12):
        // cached_aggregations rows carry NO events count and NO per-event
        // running total, so `count` is hardcoded to 1 and `running_total`
        // collapses to [units]. Fees therefore emit events_count: 1 and the
        // percentage model cannot fire its per-event branches (paid_events,
        // fixed_fee_total_amount) — Rails computes both from the events store
        // (BillableMetrics::Aggregations::SumService#running_total). Do NOT
        // fake these from the units value; they arrive with M2 live event
        // aggregation.
        $units = $cached?->current_aggregation ?? '0';

        $count = (int) ($cached?->created_at === null ? 0 : 1);

        return new AggregationResult(
            aggregation: (string) $units,
            count: max(0, $count),
            options: ['running_total' => [(string) $units]],
        );
    }

    /** Zero-unit result — port of the aggregations' `empty_results`. */
    public function emptyResults(): AggregationResult
    {
        return AggregationResult::empty();
    }

    private function latestCachedAggregation(): ?CachedAggregation
    {
        return CachedAggregation::query()
            ->where('external_subscription_id', $this->externalSubscriptionId)
            ->where('charge_id', $this->meteredItem->chargeId())
            ->whereNull('charge_filter_id')
            ->latest('timestamp')
            ->latest()
            ->first();
    }
}
