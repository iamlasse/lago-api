<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * billing_segments.status — native Postgres enum `billing_segment_status`
 * ('pending', 'processing', 'done', 'failed'). String-backed values match
 * the PG enum labels exactly (Rails: BillingSegment::STATUSES, enum with
 * validate: true, prefix: true). Schema default is 'pending'.
 */
enum BillingSegmentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    /** @return list<string> Rails' BillingSegment::STATUSES names, in order. */
    public static function options(): array
    {
        return ['pending', 'processing', 'done', 'failed'];
    }

    /**
     * Rails assigns the enum NAME ("pending" | …) and the column stores the
     * same label; returns the label, or null when the name is not one of the
     * options (Rails: enum validation records the bad assignment instead of
     * raising).
     */
    public static function fromOption(mixed $value): ?string
    {
        if ($value instanceof self) {
            return $value->value;
        }

        return in_array($value, self::options(), true) ? (string) $value : null;
    }
}
