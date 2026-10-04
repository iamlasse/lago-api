<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * rate_card_rates.billing_interval_unit (and rate_overrides) — native
 * Postgres enum `rate_card_rate_billing_interval_unit`
 * ('day', 'week', 'month', 'year'). String-backed values match the PG enum
 * labels exactly (Rails: RateCardRate::BILLING_INTERVAL_UNITS).
 */
enum RateCardRateBillingIntervalUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
