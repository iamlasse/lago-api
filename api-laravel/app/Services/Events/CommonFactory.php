<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use Carbon\CarbonImmutable;
use App\Support\Utils\Datetime;

/**
 * Port of Rails' Events::CommonFactory
 * (app/services/events/common_factory.rb) + the hash branches of
 * Events::Common (app/models/events/common.rb) — rebuilds an in-memory
 * Event from a serialized source (the raw Kafka payload hash on the
 * charged-in-advance consumer path).
 *
 * Rails returns an `Events::Common` struct; the port reuses the Event
 * model as the in-memory carrier (the pay-in-advance chain already takes
 * an Event). The instance is NOT persisted.
 *
 * TODO(port): the `Events::Common` / `Clickhouse::EventsRaw` source
 * branches (the ClickHouse dual-write path is not landed).
 */
final class CommonFactory
{
    /**
     * Port of `CommonFactory.new_instance(source:)` for the Hash branch —
     * the shape the Kafka charged-in-advance topic carries.
     */
    public static function newInstanceFromHash(array $source): Event
    {
        $event = new Event();
        $event->id = $source['id'] ?? null;
        $event->organization_id = $source['organization_id'] ?? null;
        $event->transaction_id = $source['transaction_id'] ?? null;
        $event->external_subscription_id = $source['external_subscription_id'] ?? null;
        $event->timestamp = self::timestampFromSource($source);
        $event->code = $source['code'] ?? null;
        $event->properties = $source['properties'] ?? [];

        if (isset($source['precise_total_amount_cents']) && $source['precise_total_amount_cents'] !== null && $source['precise_total_amount_cents'] !== '') {
            $event->precise_total_amount_cents = CreateService::sanitizePreciseAmount($source['precise_total_amount_cents']);
        }

        return $event;
    }

    /**
     * Port of `Events::Common.timestamp_from_source` — the
     * `timestamp_with_precision` iso8601 string wins (it carries the
     * received nanosecond precision), the float `timestamp` is the
     * fallback. Rails rescues TypeError/ArgumentError into the float
     * parse; Datetime::parseIso8601 returns null for the same inputs.
     */
    private static function timestampFromSource(array $source): ?CarbonImmutable
    {
        $withPrecision = $source['timestamp_with_precision'] ?? null;

        if ($withPrecision !== null && $withPrecision !== '') {
            $parsed = Datetime::parseIso8601($withPrecision);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        $timestamp = $source['timestamp'] ?? null;

        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return CarbonImmutable::createFromTimestamp((float) $timestamp, config('app.timezone'));
    }
}
