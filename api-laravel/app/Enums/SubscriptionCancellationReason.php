<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * subscriptions.cancellation_reason — native Postgres enum
 * `subscription_cancellation_reasons` (app/models/subscription.rb
 * CANCELLATION_REASONS). Value stored/emitted verbatim as string.
 *
 * NOTE: the schema also carries the legacy (older) spelling
 * `subscription_cancelation_reasons` on the ignored column
 * `cancelation_reason` — that column is not readable through this model.
 */
enum SubscriptionCancellationReason: string
{
    case PaymentFailed = 'payment_failed';
    case Timeout = 'timeout';
    case Manual = 'manual';

    /** Rails: Subscription::CANCELLATION_REASONS values. */
    public static function options(): array
    {
        return ['payment_failed', 'timeout', 'manual'];
    }
}
