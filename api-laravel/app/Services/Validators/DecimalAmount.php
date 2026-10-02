<?php

declare(strict_types=1);

namespace App\Services\Validators;

/**
 * Port of Rails' Validators::DecimalAmountService
 * (app/services/validators/decimal_amount_service.rb).
 *
 * NOTE: Rails only accepts amounts given as STRINGS to avoid float parsing
 * imprecision ("as we want to be the more precise with decimals") and uses
 * BigDecimal as the source of truth. Comparison math here uses bcmath —
 * floats are banned in billing math. Rails' ArgumentError/TypeError rescue on
 * unparseable input maps to false here (null and non-strings are invalid).
 */
final class DecimalAmount
{
    public static function validAmount(mixed $amount): bool
    {
        $canonical = self::canonical($amount);

        if ($canonical === null) {
            return false;
        }

        // decimal_amount.zero? || decimal_amount.positive?
        return bccomp($canonical, '0', 20) >= 0;
    }

    public static function validPositiveAmount(mixed $amount): bool
    {
        $canonical = self::canonical($amount);

        if ($canonical === null) {
            return false;
        }

        return bccomp($canonical, '0', 20) === 1;
    }

    /**
     * Rails' BigDecimal(amount) — accepts strictly-formatted decimal strings
     * (including scientific notation, which is normalized here), nothing else.
     * Returns the canonical plain decimal string, or null when invalid.
     */
    public static function canonical(mixed $amount): ?string
    {
        if (! is_string($amount)) {
            return null;
        }

        if (! preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE]([+-]?\d+))?\z/', $amount, $matches)) {
            return null;
        }

        $sign = '';
        $digits = $amount;

        if ($digits[0] === '+' || $digits[0] === '-') {
            $sign = $digits[0] === '-' ? '-' : '';
            $digits = mb_substr($digits, 1);
        }

        $exponent = isset($matches[1]) ? (int) $matches[1] : 0;

        // Strip the exponent part before normalizing the mantissa.
        $digits = preg_split('/[eE]/', $digits)[0];

        if ($exponent !== 0) {
            $digits = self::applyExponent($digits, $exponent);
        }

        $canonical = $sign.$digits;

        // Normalize "-0", trailing no-op zeros comparison is handled by bccomp.
        if (preg_match('/\A-?0*(?:\.0*)?\z/', $canonical)) {
            return '0';
        }

        return $canonical;
    }

    private static function applyExponent(string $digits, int $exponent): string
    {
        $dot = mb_strpos($digits, '.');

        if ($dot === false) {
            $intDigits = $digits;
            $fracDigits = '';
        } else {
            $intDigits = mb_substr($digits, 0, $dot);
            $fracDigits = mb_substr($digits, $dot + 1);
        }

        if ($exponent > 0) {
            while ($exponent > 0 && $fracDigits !== '') {
                $intDigits .= $fracDigits[0];
                $fracDigits = mb_substr($fracDigits, 1);
                $exponent--;
            }

            $intDigits .= str_repeat('0', $exponent);
        } else {
            while ($exponent < 0 && $intDigits !== '') {
                $fracDigits = mb_substr($intDigits, -1).$fracDigits;
                $intDigits = mb_substr($intDigits, 0, -1);
                $exponent++;
            }

            if ($exponent < 0) {
                $fracDigits = str_repeat('0', -$exponent).$fracDigits;
            }

            if ($intDigits === '') {
                $intDigits = '0';
            }
        }

        if ($fracDigits === '') {
            return $intDigits;
        }

        return $intDigits.'.'.$fracDigits;
    }
}
