<?php

declare(strict_types=1);

namespace App\Services\Validators;

use DateTimeZone;
use DateTimeImmutable;

/**
 * Port of the LagoEuVat::Rate gem (lib/lago_eu_vat in the Rails repo):
 * EU VAT rates and special-territory exceptions per country, from the
 * ibericode/vat-rates dataset (committed verbatim in data/eu_vat_rates.json).
 */
final class EuVatRates
{
    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $countryRates = null;

    /** @return list<string> ISO codes of the covered (EU) countries. */
    public static function countryCodes(): array
    {
        return array_keys(self::rates());
    }

    /**
     * Port of `Rate.country_rates(country_code:)` — country rates are
     * ordered by date, so select the most recent applicable period and
     * return its `rates` and `exceptions`.
     *
     * @return array{rates: array<string, mixed>, exceptions: list<array<string, mixed>>}|null
     */
    public static function countryRates(string $countryCode): ?array
    {
        $periods = self::rates()[mb_strtoupper($countryCode)] ?? null;

        if ($periods === null) {
            return null;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($periods as $period) {
            $effectiveFrom = new DateTimeImmutable($period['effective_from'], new DateTimeZone('UTC'));

            if ($now >= $effectiveFrom) {
                return [
                    'rates' => $period['rates'],
                    'exceptions' => $period['exceptions'] ?? [],
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private static function rates(): array
    {
        if (self::$countryRates === null) {
            $raw = json_decode(
                (string) file_get_contents(__DIR__.'/data/eu_vat_rates.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::$countryRates = $raw['items'];
        }

        return self::$countryRates;
    }
}
