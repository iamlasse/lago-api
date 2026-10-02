<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * invoice_subscriptions.invoicing_reason — native Postgres enum
 * `subscription_invoicing_reason` (app/models/invoice_subscription.rb
 * INVOICING_REASONS). Value stored/emitted verbatim as string.
 */
enum SubscriptionInvoicingReason: string
{
    case SubscriptionStarting = 'subscription_starting';
    case SubscriptionPeriodic = 'subscription_periodic';
    case SubscriptionTerminating = 'subscription_terminating';
    case InAdvanceCharge = 'in_advance_charge';
    case InAdvanceChargePeriodic = 'in_advance_charge_periodic';
    case ProgressiveBilling = 'progressive_billing';

    /** Rails: InvoiceSubscription::INVOICING_REASONS values. */
    public static function options(): array
    {
        return [
            'subscription_starting',
            'subscription_periodic',
            'subscription_terminating',
            'in_advance_charge',
            'in_advance_charge_periodic',
            'progressive_billing',
        ];
    }
}
