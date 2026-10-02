<?php

declare(strict_types=1);

namespace App\Services\Charges\Validators\Concerns;

/**
 * Port of Rails' Validators::RangeBoundsValidator
 * (app/services/validators/range_bounds_validator.rb) — shared bound checks
 * for the graduated / volume / graduated-percentage tier validators.
 */
trait RangeBounds
{
    /** BigDecimal(value.to_s) — int bounds pass through, anything else is invalid. */
    protected static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $range
     */
    protected function validBounds(array $range, int $index, int $nextFromValue): bool
    {
        $from = self::decimal($range['from_value'] ?? null);
        $nextFrom = self::decimal($nextFromValue);

        if ($from === null || $nextFrom === null) {
            return false;
        }

        $validFrom = bccomp($from, $nextFrom, 20) === 0
            || bccomp($from, bcadd($nextFrom, '1', 0), 20) === 0;

        if (! $validFrom) {
            return false;
        }

        if ($index === (count($this->ranges()) - 1)) {
            return ($range['to_value'] ?? null) === null;
        }

        $to = self::decimal($range['to_value'] ?? 0);

        return $to !== null && bccomp($to, $from, 20) === 1;
    }
}
