<?php

namespace App\Services\Validators;

use ResourceBundle;

/**
 * Port of Rails' CountryCodeValidator data source (ISO3166 gem):
 * a country code is valid when `ISO3166::Country.new(code).present?`, i.e.
 * when it is a known ISO 3166-1 alpha-2 code. We resolve codes from the ICU
 * region data shipped with the intl extension (same ISO 3166 list).
 */
final class Countries
{
    /** @var list<string>|null */
    private static ?array $codes = null;

    public static function valid(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return in_array(strtoupper($code), self::all(), true);
    }

    /** @return list<string> ISO 3166-1 alpha-2 codes. */
    public static function all(): array
    {
        if (self::$codes === null) {
            $bundle = ResourceBundle::create('en', 'ICUDATA/region');

            self::$codes = $bundle === null
                ? []
                : array_values(array_filter($bundle->keySet() ?? [], fn ($c) => strlen((string) $c) === 2));
        }

        return self::$codes;
    }
}
