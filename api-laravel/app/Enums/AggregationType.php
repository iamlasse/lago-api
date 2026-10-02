<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * billable_metrics.aggregation_type — integer column, Rails enum order is
 * the stored value (app/models/billable_metric.rb AGGREGATION_TYPES):
 * count_agg:0, sum_agg:1, max_agg:2, unique_count_agg:3, weighted_sum_agg:5,
 * latest_agg:6, custom_agg:7.
 *
 * NOTE: 4 (recurring_count_agg) is a DELETED aggregation type — the gap is
 * intentional. Never renumber.
 */
enum AggregationType: int
{
    case CountAgg = 0;
    case SumAgg = 1;
    case MaxAgg = 2;
    case UniqueCountAgg = 3;
    // NOTE: 4 was deleted (recurring_count_agg) — never renumber.
    case WeightedSumAgg = 5;
    case LatestAgg = 6;
    case CustomAgg = 7;

    /** @return list<string> Rails' AGGREGATION_TYPES keys, in order. */
    public static function options(): array
    {
        return [
            'count_agg',
            'sum_agg',
            'max_agg',
            'unique_count_agg',
            'weighted_sum_agg',
            'latest_agg',
            'custom_agg',
        ];
    }

    /**
     * Rails assigns the enum NAME ("count_agg", "sum_agg", …) and the column
     * stores the integer position; returns the position, or null when the
     * name is not one of the options (Rails' custom `aggregation_type=`
     * setter silently keeps the previous value in that case).
     */
    public static function fromOption(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return self::tryFrom($value);
        }

        return match (is_string($value) ? $value : null) {
            'count_agg' => self::CountAgg,
            'sum_agg' => self::SumAgg,
            'max_agg' => self::MaxAgg,
            'unique_count_agg' => self::UniqueCountAgg,
            'weighted_sum_agg' => self::WeightedSumAgg,
            'latest_agg' => self::LatestAgg,
            'custom_agg' => self::CustomAgg,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return \Illuminate\Support\Str::snake($this->name);
    }

    /** Rails: AGGREGATION_TYPES_PAYABLE_IN_ADVANCE. */
    public function payableInAdvance(): bool
    {
        return in_array($this, [self::CountAgg, self::SumAgg, self::UniqueCountAgg, self::CustomAgg], true);
    }
}
