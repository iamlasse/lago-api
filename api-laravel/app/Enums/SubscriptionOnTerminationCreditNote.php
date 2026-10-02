<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * subscriptions.on_termination_credit_note — native Postgres enum
 * `subscription_on_termination_credit_note` (app/models/subscription.rb
 * ON_TERMINATION_CREDIT_NOTES). Value stored/emitted verbatim as string.
 */
enum SubscriptionOnTerminationCreditNote: string
{
    case Credit = 'credit';
    case Skip = 'skip';
    case Refund = 'refund';
    case Offset = 'offset';

    /** Rails: Subscription::ON_TERMINATION_CREDIT_NOTES values. */
    public static function options(): array
    {
        return ['credit', 'skip', 'refund', 'offset'];
    }
}
