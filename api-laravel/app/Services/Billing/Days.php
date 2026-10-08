<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonInterface;

/**
 * Port of Rails' Billing::Days (app/services/billing/days.rb) — whole
 * billable days in a half-open window. A day belongs to the window holding
 * its LOCAL midnight.
 */
final class Days
{
    /**
     * Rails: `Days.between(from, to, timezone:)` — a day begun counts whole.
     * Days are counted local midnight to local midnight, so a DST-short day
     * still counts as one.
     */
    public static function between(CarbonInterface $from, CarbonInterface $to, string $timezone): int
    {
        return (int) self::openingDate($from, $timezone)->diffInDays(self::openingDate($to, $timezone));
    }

    /**
     * Rails: `Days.opening_date` (private) — the first local date whose
     * midnight is at or after the timestamp. Compared against midnight rather
     * than computed by addition, so a DST transition cannot shift it.
     */
    private static function openingDate(CarbonInterface $timestamp, string $timezone): \Carbon\CarbonImmutable
    {
        $local = \Carbon\CarbonImmutable::parse($timestamp)->setTimezone($timezone);
        $midnight = $local->startOfDay();

        // The day in progress was already given to whatever window opened it;
        // the next one to hand out is the next.
        return $local->equalTo($midnight) ? $local->startOfDay() : $local->addDay()->startOfDay();
    }
}
