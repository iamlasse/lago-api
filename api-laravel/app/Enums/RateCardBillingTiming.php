<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * rate_cards.billing_timing — native Postgres enum
 * `rate_card_billing_timing` ('arrears', 'advance'). String-backed values
 * match the PG enum labels exactly (Rails: RateCard::BILLING_TIMINGS).
 * Schema default is 'arrears'.
 */
enum RateCardBillingTiming: string
{
    case Arrears = 'arrears';
    case Advance = 'advance';
}
