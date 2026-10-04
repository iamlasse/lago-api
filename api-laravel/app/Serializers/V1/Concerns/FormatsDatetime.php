<?php

declare(strict_types=1);

namespace App\Serializers\V1\Concerns;

use DateTimeZone;
use DateTimeInterface;

/**
 * Shared datetime formatting for the V1 serializer ports — Rails serializers
 * emit `model.created_at.iso8601` (UTC, "Z" suffix).
 */
trait FormatsDatetime
{
    protected function serializeDatetime(mixed $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        // Model columns surface as Carbon instances, but datetime strings
        // reach the trait from paths that bypass Eloquent casting (joined
        // selects, aggregations), so parse those too.
        if ($datetime instanceof DateTimeInterface) {
            return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        return \Carbon\CarbonImmutable::parse((string) $datetime, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Rails: `iso8601` on a date — ActiveRecord surfaces DATE columns as
     * Ruby Date objects, whose iso8601 is the plain "Y-m-d" (no time part).
     * Model columns surface as Carbon instances here, but plain 'Y-m-d'
     * strings reach the trait from aggregations (e.g. contract applied rate
     * card anchors selected outside Eloquent casting), so parse those too.
     */
    protected function serializeDate(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof DateTimeInterface) {
            return \Carbon\CarbonImmutable::instance($date)->utc()->format('Y-m-d');
        }

        return \Carbon\CarbonImmutable::parse((string) $date, 'UTC')->format('Y-m-d');
    }
}
