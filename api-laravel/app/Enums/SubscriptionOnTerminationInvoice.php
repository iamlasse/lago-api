<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * subscriptions.on_termination_invoice — native Postgres enum
 * `subscription_on_termination_invoice` (app/models/subscription.rb
 * ON_TERMINATION_INVOICES). Schema default 'generate'. Value stored/emitted
 * verbatim as string.
 */
enum SubscriptionOnTerminationInvoice: string
{
    case Generate = 'generate';
    case Skip = 'skip';

    /** Rails: Subscription::ON_TERMINATION_INVOICES values. */
    public static function options(): array
    {
        return ['generate', 'skip'];
    }
}
