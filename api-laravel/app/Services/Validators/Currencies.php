<?php

declare(strict_types=1);

namespace App\Services\Validators;

/**
 * Port of Rails' Currencies concern ACCEPTED_CURRENCIES keys
 * (app/models/concerns/currencies.rb) — `currency_list` for the
 * `inclusion` validation shared by Organization / BillingEntity / Customer.
 */
final class Currencies
{
    /**
     * @var list<string>|null
     */
    private static ?array $list = null;

    /** @return list<string> uppercase ISO 4217 codes Rails accepts. */
    public static function list(): array
    {
        self::$list ??= [
            'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA',
            'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD',
            'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND',
            'BOB', 'BRL', 'BSD', 'BWP', 'BYN', 'BZD',
            'CAD', 'CDF', 'CHF', 'CLF', 'CLP', 'CNY',
            'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK',
            'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD',
            'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD',
            'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK',
            'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IRR',
            'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS',
            'KHR', 'KMF', 'KRW', 'KWD', 'KYD', 'KZT',
            'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD',
            'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP',
            'MRO', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR',
            'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR',
            'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR',
            'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB',
            'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD',
            'SHP', 'SLL', 'SOS', 'SRD', 'STD', 'SYP',
            'SZL', 'THB', 'TJS', 'TND', 'TOP', 'TRY',
            'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD',
            'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF',
            'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
        ];

        return self::$list;
    }

    public static function valid(?string $currency): bool
    {
        return $currency !== null && in_array(mb_strtoupper($currency), self::list(), true);
    }
}
