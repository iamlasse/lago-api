<?php

namespace App\Enums;

/**
 * customers/billing_entities.subscription_invoice_issuing_date_anchor —
 * native Postgres enum `subscription_invoice_issuing_date_anchors`.
 */
enum SubscriptionInvoiceIssuingDateAnchor: string
{
    case CurrentPeriodEnd = 'current_period_end';
    case NextPeriodStart = 'next_period_start';
}
