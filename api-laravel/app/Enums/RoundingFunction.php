<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * billable_metrics.rounding_function — native Postgres enum
 * `billable_metric_rounding_function` ('round', 'floor', 'ceil').
 * String-backed values match the PG enum labels exactly
 * (Rails: ROUNDING_FUNCTIONS = {round: "round", ceil: "ceil", floor: "floor"}).
 */
enum RoundingFunction: string
{
    case Round = 'round';
    case Floor = 'floor';
    case Ceil = 'ceil';

    /** @return list<string> Rails' ROUNDING_FUNCTIONS values. */
    public static function options(): array
    {
        return ['round', 'ceil', 'floor'];
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
            'round' => self::Round,
            'ceil' => self::Ceil,
            'floor' => self::Floor,
            default => null,
        };
    }
}
