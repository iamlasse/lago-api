<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money/decimal math helpers porting Ruby's rounding semantics for the
 * billing pipeline.
 *
 * Ruby's `.round` (Float#round and BigDecimal#round with no argument) rounds
 * halves AWAY FROM ZERO ("half-up" on positive cents): 2.5 -> 3, -2.5 -> -3.
 * PHP's round() rounds halves away from zero too, but operates on binary
 * floats and shows precision artifacts (round(2.675, 2) etc.). All billing
 * math therefore goes through bcmath on the decimal string representation:
 * add half a unit (away from zero), then truncate toward zero — exact
 * decimal arithmetic, no float error.
 */
final class MoneyMath
{
    /** Default working scale for intermediate bcmath operations. */
    public const SCALE = 15;

    /**
     * Port of Ruby's `.round` (no precision argument) — returns an int,
     * rounding halves away from zero.
     */
    public static function round(string|int|float $value): int
    {
        $dec = self::toDecimalString($value);

        if (str_starts_with($dec, '-')) {
            return (int) bcmul(bcadd($dec, '-0.5', self::SCALE), '1', 0);
        }

        return (int) bcmul(bcadd($dec, '0.5', self::SCALE), '1', 0);
    }

    /**
     * Port of Ruby's `.ceil` (no precision argument) — returns an int,
     * rounding up (toward positive infinity).
     */
    public static function ceil(string|int|float $value): int
    {
        $dec = self::toDecimalString($value);
        $truncated = (int) bcmul($dec, '1', 0);

        // exact integer already
        if (bccomp($dec, (string) $truncated, self::SCALE) === 0) {
            return $truncated;
        }

        return $dec[0] === '-' ? $truncated : $truncated + 1;
    }

    /**
     * Port of Ruby's `.floor` (no precision argument).
     */
    public static function floor(string|int|float $value): int
    {
        $dec = self::toDecimalString($value);
        $truncated = (int) bcmul($dec, '1', 0);

        if (bccomp($dec, (string) $truncated, self::SCALE) === 0) {
            return $truncated;
        }

        return $dec[0] === '-' ? $truncated - 1 : $truncated;
    }

    /**
     * Port of Ruby's `.round(precision)` — returns a decimal string with
     * exactly $precision fractional digits, halves away from zero.
     */
    public static function roundTo(string|int|float $value, int $precision = 0): string
    {
        $dec = self::toDecimalString($value);
        $negative = str_starts_with($dec, '-');
        if ($negative) {
            $dec = mb_substr($dec, 1);
        }

        $shifted = bcmul($dec, bcpow('10', (string) $precision, 0), self::SCALE);
        $shifted = bcadd($shifted, '0.5', self::SCALE);
        $result = bcdiv($shifted, bcpow('10', (string) $precision, 0), $precision);

        return $negative ? '-'.$result : $result;
    }

    /** a + b on decimals, returning a decimal string. */
    public static function add(string|int|float $a, string|int|float $b, int $scale = self::SCALE): string
    {
        return bcadd(self::toDecimalString($a), self::toDecimalString($b), $scale);
    }

    /** a - b on decimals, returning a decimal string. */
    public static function sub(string|int|float $a, string|int|float $b, int $scale = self::SCALE): string
    {
        return bcsub(self::toDecimalString($a), self::toDecimalString($b), $scale);
    }

    /** a * b on decimals, returning a decimal string. */
    public static function mul(string|int|float $a, string|int|float $b, int $scale = self::SCALE): string
    {
        return bcmul(self::toDecimalString($a), self::toDecimalString($b), $scale);
    }

    /**
     * Ruby's `.fdiv` / BigDecimal division — a/b as a decimal string with
     * high precision. Ruby floats carry ~15-17 significant digits; we keep
     * 15 fractional digits, matching the frozen numeric(40,15) columns.
     * BigDecimal#div ROUNDS at its precision limit (half away from zero —
     * golden fee precise_unit_amount "10.810810810810811"), so the quotient
     * is computed one digit past scale and rounded, never truncated.
     */
    public static function fdiv(string|int|float $a, string|int|float $b, int $scale = self::SCALE): string
    {
        $divisor = self::toDecimalString($b);

        if (bccomp($divisor, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        return self::roundTo(bcdiv(self::toDecimalString($a), $divisor, $scale + 1), $scale);
    }

    /**
     * Port of Rails' `Float#to_d` (BigDecimal(float) with no precision, the
     * capture image's Ruby 4) — the double's exact binary expansion
     * TRUNCATED at 16 significant digits, rendered fixed-notation
     * ("4900.fdiv(31)" → 158.06451612903225|6 → "158.0645161290322").
     * PHP's `(string) float` casts to 14 significant digits instead, which
     * silently truncates every float that flows into a precise_* column.
     */
    public static function floatToDecimal(float $value): string
    {
        $negative = $value < 0.0 ? '-' : '';

        // %.30F emits the double's exact decimal expansion for every
        // magnitude that reaches a precise_* column (doubles are exact to
        // ~17 significant digits; 30 decimals carries well past that).
        [$intPart, $fraction] = explode('.', sprintf('%.30F', abs($value)));

        if ($intPart !== '0') {
            $digits = $intPart.$fraction;
            $digits = mb_substr($digits, 0, 16);
            // BigDecimal's digit representation carries no trailing zeros
            // ("24.0", not "24.00000000000000").
            $digits = mb_rtrim($digits, '0');
            if ($digits === '') {
                return '0';
            }
            $point = mb_strlen($intPart);

            if ($point >= mb_strlen($digits)) {
                return $negative.$digits.str_repeat('0', $point - mb_strlen($digits)).'.0';
            }

            return $negative.mb_substr($digits, 0, $point).'.'.mb_substr($digits, $point);
        }

        $stripped = mb_ltrim($fraction, '0');

        if ($stripped === '') {
            return '0';
        }

        $digits = mb_rtrim(mb_substr($stripped, 0, 16), '0');
        $leadingZeros = mb_strlen($fraction) - mb_strlen($stripped);

        if ($digits === '') {
            return '0';
        }

        return $negative.'0.'.str_repeat('0', $leadingZeros).$digits;
    }

    /**
     * Port of Rails' BigDecimal#/ (BigDecimal#fdiv with a BigDecimal
     * divisor) — the exact quotient TRUNCATED at 16 significant digits
     * ("189.67741935483864".fdiv(100.to_d) → "1.896774193548386", not
     * "...3864"). Non-terminating quotients are beyond the fee columns'
     * numeric(40,15) scale, so the truncation is the only visible rule.
     */
    public static function truncateSignificant(string $numeric, int $digits = 16): string
    {
        $negative = str_starts_with($numeric, '-') ? '-' : '';
        $numeric = mb_ltrim($numeric, '-');

        if (! str_contains($numeric, '.')) {
            $numeric .= '.0';
        }

        [$int, $fraction] = explode('.', $numeric);
        $all = $int.$fraction;
        $stripped = mb_ltrim($all, '0');

        if ($stripped === '') {
            return '0';
        }

        $firstSignificant = mb_strlen($all) - mb_strlen($stripped);
        $keep = max(mb_strlen($int), $firstSignificant + $digits);

        $kept = mb_strlen($all) >= $keep
            ? mb_substr($all, 0, $keep)
            : $all.str_repeat('0', $keep - mb_strlen($all));

        $result = mb_substr($kept, 0, mb_strlen($int)).'.'.mb_substr($kept, mb_strlen($int));

        return $negative.$result;
    }

    /**
     * Port of Rails' BigDecimal#to_s("F") — the JSON form ActiveSupport uses
     * for BigDecimal in jsonb / API payloads: fixed notation, trailing
     * FRACTIONAL zeros trimmed, at least one fractional digit.
     * "2400" -> "2400.0", "28.8000000000" -> "28.8", "0.00000" -> "0.0".
     * Integer input is never touched ("250" keeps its zero — "250.0").
     */
    public static function toF(string $numeric): string
    {
        $trimmed = $numeric;

        if (str_contains($trimmed, '.')) {
            $trimmed = mb_rtrim(mb_rtrim($trimmed, '0'), '.');
        }

        if ($trimmed === '' || $trimmed === '-') {
            $trimmed = '0';
        }

        if (! str_contains($trimmed, '.')) {
            $trimmed .= '.0';
        }

        return $trimmed;
    }

    /** Decimal comparison: -1, 0, 1. */
    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::toDecimalString($a), self::toDecimalString($b), self::SCALE);
    }

    /**
     * Normalizes ints, floats and numeric strings to a fixed-scale decimal
     * string without float artifacts (floats are formatted with %.15F, the
     * shortest representation that keeps 15 fractional digits stable for
     * cent-scale values).
     */
    public static function toDecimalString(string|int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $trimmed = mb_trim($value);

            if (! is_numeric($trimmed)) {
                throw new InvalidArgumentException("Not a numeric value: {$value}");
            }

            return self::normalize($trimmed);
        }

        return self::normalize(sprintf('%.15F', $value));
    }

    private static function normalize(string $numeric): string
    {
        // bcmath rejects exponent notation; expand it.
        if (str_contains(mb_strtolower($numeric), 'e')) {
            $numeric = self::expandExponent(mb_strtolower($numeric));
        }

        if (! str_contains($numeric, '.')) {
            return $numeric === '' || $numeric === '-' ? '0' : $numeric;
        }

        // strip trailing fractional zeros / bare dot ("3.00" -> "3")
        $trimmed = mb_rtrim(mb_rtrim($numeric, '0'), '.');

        return ($trimmed === '' || $trimmed === '-') ? '0' : (string) $trimmed;
    }

    private static function expandExponent(string $numeric): string
    {
        $negative = str_starts_with($numeric, '-');
        if ($negative) {
            $numeric = mb_substr($numeric, 1);
        }

        [$mantissa, $exponent] = explode('e', $numeric);
        $exponent = (int) $exponent;
        [$intPart, $fracPart] = array_pad(explode('.', $mantissa), 2, '');

        $digits = $intPart.$fracPart;
        $point = mb_strlen($intPart) + $exponent;

        if ($point <= 0) {
            $expanded = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= mb_strlen($digits)) {
            $expanded = $digits.str_repeat('0', $point - mb_strlen($digits));
        } else {
            $expanded = mb_substr($digits, 0, $point).'.'.mb_substr($digits, $point);
        }

        return ($negative ? '-' : '').$expanded;
    }
}
