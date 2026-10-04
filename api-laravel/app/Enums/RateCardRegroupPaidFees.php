<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * rate_cards.regroup_paid_fees — native Postgres enum
 * `rate_card_regroup_paid_fees` ('none', 'invoice'). Nullable: nil means
 * the paid fee stays standalone (Rails: RateCard::REGROUP_PAID_FEES keeps
 * only `invoice`; the schema carries `none` too).
 */
enum RateCardRegroupPaidFees: string
{
    case None = 'none';
    case Invoice = 'invoice';
}
