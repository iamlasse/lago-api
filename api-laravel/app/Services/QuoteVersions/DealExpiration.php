<?php

declare(strict_types=1);

namespace App\Services\QuoteVersions;

use App\Models\QuoteVersion;
use App\Support\Utils\Datetime;

/**
 * Port of Rails' QuoteVersions::DealExpiration
 * (app/services/quote_versions/deal_expiration.rb).
 *
 * A quote carries dates the execution flow re-validates and refuses in the
 * past: the subscription ending date, a wallet expiration and a recurring
 * rule expiration. Whichever comes first bounds the whole deal, so a signing
 * window or an execution date landing after it produces a failed order rather
 * than a subscription. one_off quotes carry none of them.
 */
class DealExpiration
{
    /**
     * @return string|null the earliest bounding date, as Y-m-d
     */
    public static function earliest(QuoteVersion $quoteVersion): ?string
    {
        $items = self::billingItems($quoteVersion);

        $dates = [
            ...self::planEndDates($items),
            ...self::walletExpirations($items),
        ];

        $parsed = [];
        foreach ($dates as $date) {
            $parsedDate = Datetime::parseIso8601($date)?->toDateString();

            if ($parsedDate !== null) {
                $parsed[] = $parsedDate;
            }
        }

        return $parsed === [] ? null : min($parsed);
    }

    /**
     * An unbounded deal, a blank value and a value no date can be read from
     * all pass: only a date the deal no longer covers is refused, the
     * boundary day included, since the execution flow requires the ending
     * date to be strictly after the day it runs.
     */
    public static function covers(QuoteVersion $quoteVersion, mixed $value): bool
    {
        $expiration = self::earliest($quoteVersion);

        if ($expiration === null) {
            return true;
        }

        $date = Datetime::parseIso8601($value)?->toDateString();

        if ($date === null) {
            return true;
        }

        return $date < $expiration;
    }

    /**
     * The structural pass rejects a payload that is not an object, but
     * nothing stops a caller from reading a version that never went through
     * it.
     *
     * @return array<string, mixed>
     */
    public static function billingItems(QuoteVersion $quoteVersion): array
    {
        $items = $quoteVersion->billing_items;

        return is_array($items) ? $items : [];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return list<mixed>
     */
    protected static function planEndDates(array $items): array
    {
        $dates = [];

        foreach (self::list($items['plans'] ?? []) as $plan) {
            $dates[] = is_array($plan) ? ($plan['payload']['endDate'] ?? null) : null;
        }

        return $dates;
    }

    /**
     * Several recurring rules are legitimate on a draft, only an approved
     * version is capped at one.
     *
     * @param  array<string, mixed>  $items
     * @return list<mixed>
     */
    protected static function walletExpirations(array $items): array
    {
        $dates = [];

        foreach (self::list($items['walletCredits'] ?? []) as $item) {
            $payload = is_array($item) ? ($item['payload'] ?? []) : [];
            $payload = is_array($payload) ? $payload : [];

            $dates[] = $payload['expirationAt'] ?? null;

            foreach (self::list($payload['recurringTransactionRules'] ?? []) as $rule) {
                $dates[] = is_array($rule) ? ($rule['expirationAt'] ?? null) : null;
            }
        }

        return $dates;
    }

    /**
     * @return list<mixed>
     */
    protected static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }
}
