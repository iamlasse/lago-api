<?php

declare(strict_types=1);

namespace App\Expression;

use InvalidArgumentException;

/**
 * Arbitrary-precision decimal arithmetic on canonical decimal strings —
 * the PHP stand-in for the Rust `bigdecimal` crate behind lago-expression.
 *
 * A decimal string is canonical when it carries no exponent notation and no
 * redundant sign; its scale is its number of fractional digits. Scale is
 * preserved the way Rust's BigDecimal preserves it (verified against the
 * gem at v0.2.0):
 *   - add/sub -> max(scale(lhs), scale(rhs))   ("2.40 + 1" -> "3.40")
 *   - mul     -> scale(lhs) + scale(rhs)       ("1.5 * 2"  -> "3.0")
 *   - div     -> rounded to 100 significant digits, half-up
 * Display keeps the intrinsic scale for every non-zero value ("2.40" stays
 * "2.40") and collapses any zero to "0" ("2.40 - 2.40" -> "0").
 */
final class Decimal
{
    /**
     * The significant-digit budget of a division quotient. Empirically the
     * rust bigdecimal crate rounds non-terminating quotients to 100
     * significant digits (e.g. 1/3 -> 0.{100 digits}, half-up).
     */
    public const DIVISION_SIGNIFICANT_DIGITS = 100;

    /**
     * Guard for with-scale rounding: the gem would happily try to build a
     * BigDecimal with a scale of 2^63; we refuse scales that would exhaust
     * memory long before that (far beyond any meaningful precision).
     */
    public const MAX_ROUNDING_DIGITS = 10_000;

    private function __construct() {}

    /**
     * Parses a numeric string into a canonical decimal string, preserving
     * the literal's scale. Accepts the same shapes as rust's BigDecimal
     * FromStr (optional sign, integer/fractional digits, exponent). Returns
     * null when the input is not a number.
     */
    public static function parse(string $value): ?string
    {
        if (preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/', $value, $m) !== 1) {
            return null;
        }

        $negative = $m[1] === '-';
        $hasInteger = ($m[2] ?? '') !== '';
        $int = $hasInteger ? $m[2] : '0';
        $frac = $hasInteger ? ($m[3] ?? '') : $m[4];
        $exponent = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;

        if ($exponent !== 0) {
            $digits = $int.$frac;
            $point = mb_strlen($int) + $exponent;

            if ($point <= 0) {
                $int = '0';
                $frac = str_repeat('0', -$point).$digits;
            } elseif ($point >= mb_strlen($digits)) {
                $int = $digits.str_repeat('0', $point - mb_strlen($digits));
                $frac = '';
            } else {
                $int = mb_substr($digits, 0, $point);
                $frac = mb_substr($digits, $point);
            }
        }

        return self::build($negative, $int, $frac);
    }

    /** The scale (fractional digit count) of a canonical decimal string. */
    public static function scaleOf(string $dec): int
    {
        $dot = mb_strpos($dec, '.');

        return $dot === false ? 0 : mb_strlen($dec) - $dot - 1;
    }

    /**
     * Rust BigDecimal Display semantics: keeps the intrinsic scale of any
     * non-zero value, collapses every zero (including "-0.00") to "0", and
     * drops leading zeros / redundant signs.
     */
    public static function display(string $dec): string
    {
        if (self::isZero($dec)) {
            return '0';
        }

        $normalized = bcadd($dec, '0', self::scaleOf($dec));

        return $normalized === '-0' ? '0' : $normalized;
    }

    /**
     * Port of Ruby's BigDecimal#to_s('F') (how ActiveSupport renders
     * BigDecimals in JSON): trailing fractional zeros are trimmed but at
     * least one fractional digit is kept ("2" -> "2.0", "2.350" -> "2.35").
     */
    public static function toRailsFormat(string $dec): string
    {
        if (self::isZero($dec)) {
            return '0.0';
        }

        $dec = self::display($dec);
        $dot = mb_strpos($dec, '.');

        if ($dot === false) {
            return $dec.'.0';
        }

        $trimmed = mb_rtrim($dec, '0');

        return str_ends_with($trimmed, '.') ? $trimmed.'0' : $trimmed;
    }

    public static function isZero(string $dec): bool
    {
        return bccomp($dec, '0', max(self::scaleOf($dec), 1)) === 0;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, max(self::scaleOf($a), self::scaleOf($b)));
    }

    public static function subtract(string $a, string $b): string
    {
        return bcsub($a, $b, max(self::scaleOf($a), self::scaleOf($b)));
    }

    public static function multiply(string $a, string $b): string
    {
        return bcmul($a, $b, self::scaleOf($a) + self::scaleOf($b));
    }

    public static function negate(string $a): string
    {
        return self::isZero($a) ? $a : (str_starts_with($a, '-') ? mb_substr($a, 1) : '-'.$a);
    }

    /**
     * Division rounded to 100 significant digits, half-up — the quotient
     * precision of the rust bigdecimal crate. Terminating quotients come
     * out exact after trailing-zero trimming ("2/4" -> "0.5").
     *
     * @throws InvalidArgumentException on division by zero (the gem panics)
     */
    public static function divide(string $a, string $b): string
    {
        if (self::isZero($b)) {
            throw new InvalidArgumentException('divided by zero');
        }

        $digits = self::DIVISION_SIGNIFICANT_DIGITS;

        // Upper bound on the fractional scale that yields $digits
        // significant digits; the exact bound is derived from the quotient
        // itself afterwards.
        $bound = max(0, $digits - 1 - (self::floorLog10($a) - self::floorLog10($b))) + 4;
        $quotient = bcdiv($a, $b, $bound);

        $log10 = self::floorLog10($quotient);

        // Quotients with more than $digits integer digits: round half-up at
        // the $digits-th significant digit (mirrors the gem for quotients
        // of 1e99 and beyond).
        if ($log10 + 1 > $digits) {
            $drop = $log10 + 1 - $digits;
            $quotient = self::shiftPoint(
                self::roundToInt(self::shiftPoint($quotient, -$drop), RoundingMode::HalfUp),
                $drop,
            );

            return self::display($quotient);
        }

        $scale = max(0, $digits - 1 - $log10);

        return self::display(self::trimFraction(self::roundHalfUpAt(bcdiv($a, $b, $scale + 9), $scale)));
    }

    /**
     * Port of rust BigDecimal#with_scale_round — rescales to exactly
     * $digits fractional digits (negative $digits round whole powers of
     * ten away) using the given rounding mode.
     *
     * @throws InvalidArgumentException when |$digits| exceeds the guard
     */
    public static function withScaleRound(string $dec, int $digits, RoundingMode $mode): string
    {
        if (abs($digits) > self::MAX_ROUNDING_DIGITS) {
            throw new InvalidArgumentException('Expected a decimal');
        }

        $rounded = self::roundToInt(self::shiftPoint($dec, $digits), $mode);

        if ($digits < 0) {
            return self::shiftPoint($rounded, -$digits);
        }

        if ($digits === 0) {
            return $rounded;
        }

        return self::insertPoint($rounded, $digits);
    }

    /**
     * Truncates a decimal toward zero and returns it as a PHP int, or null
     * when the value does not fit (the gem's BigDecimal#to_i64 returning
     * None -> ExpectedDecimal).
     */
    public static function truncateToInteger(string $dec): ?int
    {
        $integer = self::shiftPoint($dec, 0);

        if (bccomp($integer, (string) PHP_INT_MAX) === 1 || bccomp($integer, (string) PHP_INT_MIN) === -1) {
            return null;
        }

        return (int) $integer;
    }

    /**
     * Exact decimal point shift: moves the point $places digits to the
     * right ($places > 0) or left ($places < 0) without losing digits.
     */
    public static function shiftPoint(string $dec, int $places): string
    {
        [$negative, $int, $frac] = self::parts($dec);

        if ($places >= 0) {
            if (mb_strlen($frac) <= $places) {
                $int = $int.$frac.str_repeat('0', $places - mb_strlen($frac));
                $frac = '';
            } else {
                $int = $int.mb_substr($frac, 0, $places);
                $frac = mb_substr($frac, $places);
            }
        } else {
            $places = -$places;

            if (mb_strlen($int) <= $places) {
                $frac = str_repeat('0', $places - mb_strlen($int)).$int.$frac;
                $int = '0';
            } else {
                $frac = mb_substr($int, -$places).$frac;
                $int = mb_substr($int, 0, -$places);
            }
        }

        return self::build($negative, $int, $frac);
    }

    /**
     * Drops redundant fractional trailing zeros ("0.500...0" of a
     * terminating quotient becomes "0.5"); non-terminating expansions are
     * unaffected.
     */
    private static function trimFraction(string $dec): string
    {
        [$negative, $int, $frac] = self::parts($dec);

        return self::build($negative, $int, mb_rtrim($frac, '0'));
    }

    /**
     * @return array{bool, string, string} [is negative, integer digits, fractional digits]
     */
    private static function parts(string $dec): array
    {
        $negative = str_starts_with($dec, '-');
        $dec = $negative ? mb_substr($dec, 1) : $dec;

        [$int, $frac] = array_pad(explode('.', $dec, 2), 2, '');

        return [$negative, $int === '' ? '0' : $int, $frac];
    }

    private static function build(bool $negative, string $int, string $frac): string
    {
        $int = mb_ltrim($int, '0');

        if ($int === '') {
            $int = '0';
        }

        $sign = ($negative && ! self::digitsAreZero($int, $frac)) ? '-' : '';

        return $frac === '' ? $sign.$int : $sign.$int.'.'.$frac;
    }

    private static function digitsAreZero(string $int, string $frac): bool
    {
        return mb_trim($int.$frac, '0') === '';
    }

    /**
     * Rounds a decimal to an integer using the gem's rounding modes:
     * HalfUp is halves away from zero, Ceiling toward +inf, Floor toward
     * -inf.
     */
    private static function roundToInt(string $dec, RoundingMode $mode): string
    {
        [$negative, $int, $frac] = self::parts($dec);
        $hasFraction = mb_trim($frac, '0') !== '';

        $roundUp = match ($mode) {
            RoundingMode::HalfUp => $frac !== '' && $frac[0] >= '5',
            RoundingMode::Ceiling => $hasFraction && ! $negative,
            RoundingMode::Floor => $hasFraction && $negative,
        };

        if ($roundUp) {
            $int = bcadd($int, '1', 0);
        }

        return self::build($negative, $int, '');
    }

    /**
     * Rounds $dec (computed with surplus guard digits) half-up at exactly
     * $scale fractional digits and cuts the string at that scale.
     */
    private static function roundHalfUpAt(string $dec, int $scale): string
    {
        $guard = self::scaleOf($dec);

        if ($guard <= $scale) {
            return $dec;
        }

        [$negative, $int, $frac] = self::parts($dec);

        if ($frac[$scale] >= '5') {
            // One unit at the $scale-th fractional digit (1e-1 when $scale
            // is 0, 1e-101 when $scale is 100, ...).
            $increment = ($negative ? '-' : '')
                .($scale === 0 ? '1' : '0.'.str_repeat('0', $scale - 1).'1');
            $dec = bcadd($dec, $increment, $guard);
            [$negative, $int, $frac] = self::parts($dec);
        }

        return self::build($negative, $int, mb_substr($frac, 0, $scale));
    }

    /**
     * Reinserts a fractional part of exactly $places digits into an integer
     * string ("124", 1 -> "12.4"; "124", 5 -> "0.00124").
     */
    private static function insertPoint(string $integer, int $places): string
    {
        $negative = str_starts_with($integer, '-');
        $integer = $negative ? mb_substr($integer, 1) : $integer;

        if (mb_strlen($integer) <= $places) {
            $integer = str_repeat('0', $places - mb_strlen($integer) + 1).$integer;
        }

        $int = mb_substr($integer, 0, -$places);
        $frac = mb_substr($integer, -$places);

        return self::build($negative, $int, $frac);
    }

    /**
     * floor(log10(|dec|)); undefined for zero (callers never pass zero).
     */
    private static function floorLog10(string $dec): int
    {
        $dec = mb_ltrim($dec, '-');

        if (str_starts_with($dec, '0.')) {
            $fraction = mb_substr($dec, 2);
            $zeros = mb_strlen($fraction) - mb_strlen(mb_ltrim($fraction, '0'));

            return -($zeros + 1);
        }

        // Canonical decimals never carry leading zeros or exponents, so the
        // digit count before the point is floor(log10) + 1.
        return mb_strlen(explode('.', $dec, 2)[0]) - 1;
    }
}
