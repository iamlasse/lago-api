<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * customers/billing_entities.subscription_invoice_issuing_date_adjustment —
 * native Postgres enum `subscription_invoice_issuing_date_adjustments`.
 */
enum SubscriptionInvoiceIssuingDateAdjustment: string
{
    case KeepAnchor = 'keep_anchor';
    case AlignWithFinalizationDate = 'align_with_finalization_date';
}
