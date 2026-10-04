<?php

declare(strict_types=1);

namespace App\Services\BillableMetrics\Aggregations;

use App\Support\MoneyMath;
use App\Models\BillableMetric;
use App\Enums\RoundingFunction;

/**
 * Port of Rails' BillableMetrics::Aggregations::ApplyRoundingService
 * (app/services/billable_metrics/aggregations/apply_rounding_service.rb).
 */
final class ApplyRoundingService
{
    /** Rails' `.call(...).units`. */
    public static function units(BillableMetric $billableMetric, string $units): string
    {
        $precision = $billableMetric->rounding_precision ?? 0;

        $rounded = match ($billableMetric->rounding_function) {
            RoundingFunction::Ceil => self::ceilTo($units, $precision),
            RoundingFunction::Floor => self::floorTo($units, $precision),
            RoundingFunction::Round => MoneyMath::roundTo($units, $precision),
            default => MoneyMath::roundTo($units, 15),
        };

        // Normalize away trailing zeros of the fixed scale (BigDecimal#ceil
        // keeps its own scale, Rails' `to_s` prints the shortest form).
        return self::trimTrailingZeros($rounded);
    }

    /** BigDecimal#ceil(precision) — away from zero. */
    private static function ceilTo(string $value, int $precision): string
    {
        $factor = bcpow('10', (string) $precision);
        $scaled = bcmul($value, $factor);
        $truncated = bcadd($scaled, '0', 0);

        $ceiled = MoneyMath::compare($scaled, $truncated) > 0
            ? bcadd($truncated, '1', 0)
            : $truncated;

        return bcdiv($ceiled, $factor, $precision);
    }

    /** BigDecimal#floor(precision) — toward negative infinity. */
    private static function floorTo(string $value, int $precision): string
    {
        $factor = bcpow('10', (string) $precision);
        $scaled = bcmul($value, $factor);
        $truncated = bcadd($scaled, '0', 0);

        $floored = MoneyMath::compare($scaled, $truncated) < 0
            ? bcsub($truncated, '1', 0)
            : $truncated;

        return bcdiv($floored, $factor, $precision);
    }

    private static function trimTrailingZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return mb_rtrim(mb_rtrim($value, '0'), '.');
    }
}
