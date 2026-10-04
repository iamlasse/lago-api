<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * contracts.billing_time — native Postgres enum `contract_billing_time`
 * ('calendar', 'anniversary'). String-backed values match the PG enum
 * labels exactly (Rails: Contract::BILLING_TIMES). NOTE: unlike the legacy
 * subscriptions.billing_time integer column, contracts store the label.
 * Schema default is 'calendar'.
 */
enum ContractBillingTime: string
{
    case Calendar = 'calendar';
    case Anniversary = 'anniversary';
}
