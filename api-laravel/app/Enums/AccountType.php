<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * customers.account_type — native Postgres enum `customer_account_type`
 * ('customer', 'partner'), default 'customer'. String-backed values match
 * the PG enum labels exactly.
 */
enum AccountType: string
{
    case Customer = 'customer';
    case Partner = 'partner';
}
