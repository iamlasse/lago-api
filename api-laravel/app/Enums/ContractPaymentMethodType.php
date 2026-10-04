<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * contracts.payment_method_type — native Postgres enum
 * `contract_payment_method_type` ('provider', 'manual'). String-backed
 * values match the PG enum labels exactly (Rails:
 * Contract::PAYMENT_METHOD_TYPES, enum with prefix: true). Schema default
 * is 'provider'.
 */
enum ContractPaymentMethodType: string
{
    case Provider = 'provider';
    case Manual = 'manual';
}
