<?php

declare(strict_types=1);

namespace App\Services\Charges;

use App\Enums\AggregationType;
use App\Models\BillableMetric;

/**
 * Billable-metric aggregation-type predicates used by the charge validations
 * (Rails: the `*_agg?` enum suffix methods on BillableMetric +
 * AGGREGATION_TYPES_PAYABLE_IN_ADVANCE).
 */
final class AggregationChecks
{
    public static function isSum(?BillableMetric $metric): bool
    {
        return self::type($metric) === AggregationType::SumAgg;
    }

    public static function isLatest(?BillableMetric $metric): bool
    {
        return self::type($metric) === AggregationType::LatestAgg;
    }

    public static function isWeightedSum(?BillableMetric $metric): bool
    {
        return self::type($metric) === AggregationType::WeightedSumAgg;
    }

    public static function isCustom(?BillableMetric $metric): bool
    {
        return self::type($metric) === AggregationType::CustomAgg;
    }

    /** Rails: AGGREGATION_TYPES_PAYABLE_IN_ADVANCE = [count, sum, unique_count, custom]. */
    public static function isPayableInAdvance(?BillableMetric $metric): bool
    {
        return self::type($metric)?->payableInAdvance() ?? false;
    }

    private static function type(?BillableMetric $metric): ?AggregationType
    {
        $value = $metric?->aggregation_type;

        if ($value instanceof AggregationType) {
            return $value;
        }

        return $value === null ? null : AggregationType::tryFrom((int) $value);
    }
}
