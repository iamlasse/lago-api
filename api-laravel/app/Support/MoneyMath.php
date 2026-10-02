<?php

declare(strict_types=1);

namespace App\Support;

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
     * Port of Ruby's `.round(precision)` — returns a decimal string with
     * exactly $precision fractional digits, halves away from zero.
     */
    public static function roundTo(string|int|float $value, int $precision = 0): string
    {
        $dec = self::toDecimalString($value);
        $negative = str_starts_with($dec, '-');
        if ($negative) {
            $dec = substr($dec, 1);
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
     * Ruby's `.fdiv` — a/b as a (string of a) decimal with high precision.
     * Ruby floats carry ~15-17 significant digits; we keep 15 fractional
     * digits, matching the frozen numeric(40,15) columns.
     */
    public static function fdiv(string|int|float $a, string|int|float $b, int $scale = self::SCALE): string
    {
        $divisor = self::toDecimalString($b);

        if (bccomp($divisor, '0', self::SCALE) === 0) {
            throw new \InvalidArgumentException('Division by zero');
        }

        return bcdiv(self::toDecimalString($a), $divisor, $scale);
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
            $trimmed = trim($value);

            if (! is_numeric($trimmed)) {
                throw new \InvalidArgumentException("Not a numeric value: {$value}");
            }

            return self::normalize($trimmed);
        }

        return self::normalize(sprintf('%.15F', $value));
    }

    private static function normalize(string $numeric): string
    {
        // bcmath rejects exponent notation; expand it.
        if (str_contains(strtolower($numeric), 'e')) {
            $numeric = self::expandExponent(strtolower($numeric));
        }

        if (! str_contains($numeric, '.')) {
            return $numeric === '' || $numeric === '-' ? '0' : $numeric;
        }

        // strip trailing fractional zeros / bare dot ("3.00" -> "3")
        $trimmed = rtrim(rtrim($numeric, '0'), '.');

        return ($trimmed === '' || $trimmed === '-') ? '0' : $trimmed;
    }

    private static function expandExponent(string $numeric): string
    {
        $negative = str_starts_with($numeric, '-');
        if ($negative) {
            $numeric = substr($numeric, 1);
        }

        [$mantissa, $exponent] = explode('e', $numeric);
        $exponent = (int) $exponent;
        [$intPart, $fracPart] = array_pad(explode('.', $mantissa), 2, '');

        $digits = $intPart.$fracPart;
        $point = strlen($intPart) + $exponent;

        if ($point <= 0) {
            $expanded = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $expanded = $digits.str_repeat('0', $point - strlen($digits));
        } else {
            $expanded = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        return ($negative ? '-' : '').$expanded;
    }
}
