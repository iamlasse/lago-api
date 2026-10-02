<?php

declare(strict_types=1);

namespace App\Support\Utils;

use Exception;
use DateTimeInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Port of Rails' Utils::Datetime (app/services/utils/datetime.rb).
 *
 * All timezone-naive timestamps in the frozen schema are UTC; customer-
 * timezone math converts with `Carbon::parse($naive, 'UTC')->setTimezone($tz)`
 * and back — tz-aware values never reach the database.
 */
final class Datetime
{
    /** Rails: `datetime_like?` — anything that can format itself (Carbon). */
    public static function datetimeLike(mixed $value): bool
    {
        return $value instanceof CarbonInterface || $value instanceof DateTimeInterface;
    }

    /**
     * Rails: `parse_iso8601` — passes through datelike values, parses
     * ISO8601 strings, returns null on garbage.
     */
    public static function parseIso8601(mixed $datetime): ?CarbonImmutable
    {
        if (self::datetimeLike($datetime)) {
            return CarbonImmutable::instance($datetime);
        }

        if (! is_string($datetime)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($datetime, 'UTC');
        } catch (InvalidFormatException|Exception) {
            return null;
        }
    }

    /** Rails: `parse_iso8601_date` — same, but truncated to the day. */
    public static function parseIso8601Date(mixed $date): ?CarbonImmutable
    {
        $parsed = self::parseIso8601($date);

        return $parsed?->startOfDay();
    }

    /**
     * Rails: `valid_format?` — datelike values are always valid; strings
     * must parse as ISO8601 (or, with format :any, as any date).
     */
    public static function validFormat(mixed $datetime, string $format = 'iso8601'): bool
    {
        if (self::datetimeLike($datetime)) {
            return true;
        }

        if (! is_string($datetime)) {
            return false;
        }

        try {
            $parsed = ($format === 'any')
                ? CarbonImmutable::parse($datetime, 'UTC')
                : CarbonImmutable::createFromFormat(DATE_ATOM, $datetime, 'UTC');

            return $parsed !== false;
        } catch (InvalidFormatException|Exception) {
            return false;
        }
    }

    /** Rails: `future_date?`. */
    public static function futureDate(mixed $datetime): bool
    {
        if ($datetime instanceof CarbonInterface) {
            return $datetime->isFuture();
        }

        if (! self::validFormat($datetime, 'any')) {
            return false;
        }

        $parsedDate = CarbonImmutable::parse((string) $datetime, 'UTC');

        return $parsedDate->isFuture();
    }

    /**
     * Rails: `before_today?(datetime, timezone:)` — strictly before the
     * beginning of today in the given timezone.
     */
    public static function beforeToday(mixed $datetime, string $timezone = 'UTC'): bool
    {
        if ($datetime instanceof CarbonInterface) {
            $parsed = CarbonImmutable::instance($datetime);
        } elseif (self::validFormat($datetime, 'any') && $datetime !== null) {
            $parsed = CarbonImmutable::parse((string) $datetime, 'UTC');
        } else {
            return false;
        }

        $zoneNow = CarbonImmutable::now($timezone);

        return $parsed->setTimezone($timezone)->lt($zoneNow->startOfDay());
    }

    /**
     * Rails: `date_diff_with_timezone(from, to, timezone)` — day count
     * across a DST boundary, ceil'd.
     */
    public static function dateDiffWithTimezone(mixed $fromDatetime, mixed $toDatetime, string $timezone): int
    {
        $from = self::datetimeLike($fromDatetime)
            ? CarbonImmutable::instance($fromDatetime)
            : CarbonImmutable::parse((string) $fromDatetime, 'UTC');

        $to = self::datetimeLike($toDatetime)
            ? CarbonImmutable::instance($toDatetime)
            : CarbonImmutable::parse((string) $toDatetime, 'UTC');

        $toInTime = $to->setTimezone($timezone);
        // To make sure we do not miss a day
        if ($toInTime->equalTo($toInTime->startOfDay())) {
            $to = $to->addSecond();
        }

        $fromOffset = $from->setTimezone($timezone)->getOffset();
        $toOffset = $to->setTimezone($timezone)->getOffset();
        $offset = $fromOffset - $toOffset;

        $diffSeconds = $to->getTimestamp() - $from->getTimestamp() - $offset;

        return (int) ceil($diffSeconds / 86400);
    }

    /**
     * Fee-properties/`to_h` serialization: null stays null, datelike values
     * become ISO8601 strings, everything else passes through.
     */
    public static function serialize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface || $value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        return $value;
    }
}
