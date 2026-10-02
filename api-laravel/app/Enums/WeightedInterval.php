<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * billable_metrics.weighted_interval — native Postgres enum
 * `billable_metric_weighted_interval` ('seconds'). String-backed values match
 * the PG enum labels exactly (Rails: WEIGHTED_INTERVAL = {seconds: "seconds"}).
 */
enum WeightedInterval: string
{
    case Seconds = 'seconds';

    /** @return list<string> Rails' WEIGHTED_INTERVAL values. */
    public static function options(): array
    {
        return ['seconds'];
    }

    /**
     * Rails assigns the enum NAME symbol and the column stores the string;
     * returns the value, or null when the name is not one of the options.
     */
    public static function fromOption(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return match (is_string($value) ? $value : null) {
            'seconds' => self::Seconds,
            default => null,
        };
    }
}
