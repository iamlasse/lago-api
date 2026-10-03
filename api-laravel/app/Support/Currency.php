<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal port of the money gem's Money::Currency table — exponent and
 * subunit_to_unit for the currencies Lago bills in.
 *
 * TODO(port): the full money::Currency table (symbols, separators, first
 * day of week...) is only needed at serialization time; M1 bills in
 * standard currencies.
 */
final class Currency
{
    /** Currencies with no subunit (exponent 0). */
    private const ZERO_EXPONENT = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Currencies with a three-decimal subunit (exponent 3). */
    private const THREE_EXPONENT = ['BHD', 'KWD', 'OMR', 'TND'];

    public static function subunitToUnit(string $currency): int
    {
        $currency = mb_strtoupper($currency);

        if (in_array($currency, self::ZERO_EXPONENT, true)) {
            return 1;
        }

        return in_array($currency, self::THREE_EXPONENT, true) ? 1000 : 100;
    }

    public static function exponent(string $currency): int
    {
        $currency = mb_strtoupper($currency);

        if (in_array($currency, self::ZERO_EXPONENT, true)) {
            return 0;
        }

        return in_array($currency, self::THREE_EXPONENT, true) ? 3 : 2;
    }
}
