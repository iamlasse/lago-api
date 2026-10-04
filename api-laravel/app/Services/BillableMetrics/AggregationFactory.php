<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics;

use LogicException;
use App\Enums\AggregationType;
use App\Services\Events\Stores\StoreFactory;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Models\Billing\Context as BillingContext;
use App\Services\BillableMetrics\Aggregations\MaxService;
use App\Services\BillableMetrics\Aggregations\SumService;
use App\Services\BillableMetrics\Aggregations\BaseService;
use App\Services\BillableMetrics\Aggregations\CountService;
use App\Services\BillableMetrics\Aggregations\LatestService;
use App\Services\BillableMetrics\Aggregations\UniqueCountService;
use App\Services\BillableMetrics\Aggregations\WeightedSumService;
use App\Services\BillableMetrics\ProratedAggregations\SumService as ProratedSumService;
use App\Services\BillableMetrics\ProratedAggregations\UniqueCountService as ProratedUniqueCountService;

/**
 * Port of Rails' BillableMetrics::AggregationFactory
 * (app/services/billable_metrics/aggregation_factory.rb) — resolves the
 * aggregation service for a metered item from its billable metric's
 * aggregation type (prorated variants for prorated charges).
 */
class AggregationFactory
{
    final public function __construct() {}

    /**
     * Port of `self.new_instance`.
     *
     * @param  array<string, mixed>  $boundaries  from_datetime, to_datetime,
     *                                            charges_duration, max_timestamp
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $aggregationOptions  Rails' `aggregate(options:)`
     */
    public static function newInstance(
        MeteredItem $meteredItem,
        BillingContext $billingContext,
        bool $currentUsage = false,
        array $boundaries = [],
        array $filters = [],
        array $aggregationOptions = [],
        ?object $provider = null,
    ): BaseService {
        $eventStore = $provider === null
            ? StoreFactory::newInstance(
                $meteredItem->billableMetric()->organization,
                billingContext: $billingContext,
                kwargs: [
                    'code' => $meteredItem->billableMetric()->code,
                    'boundaries' => $boundaries,
                    'filters' => $filters,
                ],
            )
            : $provider->storeFor($meteredItem, $boundaries, $filters);

        /** @var class-string<BaseService> $serviceClass */
        $serviceClass = self::aggregatorClass($meteredItem, $currentUsage);

        return new $serviceClass(
            eventStore: $eventStore,
            meteredItem: $meteredItem,
            billingContext: $billingContext,
            boundaries: $boundaries,
            filters: $filters,
            aggregationOptions: $aggregationOptions,
        );
    }

    /** Port of `self.aggregator_class`. */
    public static function aggregatorClass(MeteredItem $meteredItem, bool $currentUsage): string
    {
        $aggregationType = $meteredItem->billableMetric()->aggregation_type;

        $payInAdvanceBlocked = fn (): LogicException => new LogicException(
            'Aggregation type not payable in advance — Rails: NotImplementedError'
        );

        return match ($aggregationType) {
            AggregationType::CountAgg => CountService::class,
            AggregationType::LatestAgg => ! $currentUsage && $meteredItem->payInAdvance() ? throw $payInAdvanceBlocked() : LatestService::class,
            AggregationType::MaxAgg => ! $currentUsage && $meteredItem->payInAdvance() ? throw $payInAdvanceBlocked() : MaxService::class,
            AggregationType::SumAgg => $meteredItem->prorated() ? ProratedSumService::class : SumService::class,
            AggregationType::UniqueCountAgg => $meteredItem->prorated() ? ProratedUniqueCountService::class : UniqueCountService::class,
            AggregationType::WeightedSumAgg => ! $currentUsage && $meteredItem->payInAdvance() ? throw $payInAdvanceBlocked() : WeightedSumService::class,
            // TODO(port): BillableMetrics::Aggregations::CustomService — the
            // custom expression aggregation (needs the per-event store API and
            // cached custom amounts; arrives with the M2 events slice).
            AggregationType::CustomAgg => throw new LogicException('Custom aggregation is not ported yet — TODO(port)'),
            default => throw new LogicException('Unknown aggregation type — Rails: NotImplementedError'),
        };
    }
}
