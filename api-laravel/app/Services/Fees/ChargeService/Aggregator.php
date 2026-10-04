<?php

declare(strict_types=1);

namespace App\Services\Fees\ChargeService;

use App\Models\Subscription;
use App\Services\ChargeModels\AggregationResult;
use App\Models\Billing\Context as BillingContext;
use App\Services\BillableMetrics\AggregationFactory;
use App\Services\BillableMetrics\Aggregations\BaseService;

/**
 * The fee engine's aggregation seam — resolves the BillableMetrics
 * aggregation service for this charge / period and runs it against the
 * events store (M2: live event aggregation).
 *
 * Rails resolves `BillableMetrics::AggregationFactory.new_instance` with
 * the metered item's aggregation boundaries and options
 * (MeteredItem#aggregation_boundaries / #aggregation_options) and calls
 * `aggregate`. The cache-vs-live decision lives inside the aggregation
 * services: periodic in-arrears billing aggregates the events LIVE and
 * never reads cached_aggregations; cached rows are only consulted on the
 * pay-in-advance current-usage paths (Aggregations::BaseService) and for
 * the recurring weighted-sum carry-over (WeightedSumService).
 */
final class Aggregator
{
    public function __construct(
        private readonly MeteredItem $meteredItem,
        private readonly Subscription $subscription,
        private readonly Options $options,
    ) {}

    public function aggregate(): AggregationResult
    {
        return $this->aggregationService()->aggregate();
    }

    /** Zero-unit result — port of the aggregations' `empty_results`. */
    public function emptyResults(): AggregationResult
    {
        return $this->aggregationService()->emptyResults();
    }

    /** Rails: Fees::ChargeService#build_aggregator + AggregationFactory. */
    private function aggregationService(): BaseService
    {
        $meteredItem = $this->meteredItem;
        $billingContext = BillingContext::fromSubscription($this->subscription);

        // Port of MeteredItem#aggregation_boundaries — the window an
        // aggregation runs on.
        $boundaries = [
            'from_datetime' => $meteredItem->boundaries->chargesFromDatetime,
            'to_datetime' => $meteredItem->boundaries->chargesToDatetimeValue(),
            'charges_duration' => $meteredItem->boundaries->chargesDuration,
            'max_timestamp' => $meteredItem->boundaries->maxTimestamp,
        ];

        // Port of Fees::ChargeService#aggregation_filters for the unfiltered
        // bucket (charge filters / pricing group keys arrive with M2).
        $filters = ['charge_id' => $meteredItem->chargeId()];

        // Port of MeteredItem#aggregation_options.
        $aggregationOptions = [
            'free_units_per_events' => (int) ($meteredItem->properties()['free_units_per_events'] ?? 0),
            'free_units_per_total_aggregation' => (string) ($meteredItem->properties()['free_units_per_total_aggregation'] ?? '0'),
            'is_current_usage' => $this->options->currentUsage(),
            'is_pay_in_advance' => $meteredItem->payInAdvance(),
        ];

        return AggregationFactory::newInstance(
            meteredItem: $meteredItem,
            billingContext: $billingContext,
            currentUsage: $this->options->currentUsage(),
            boundaries: $boundaries,
            filters: $filters,
            aggregationOptions: $aggregationOptions,
        );
    }
}
