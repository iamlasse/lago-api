<?php

namespace App\Enums;

/**
 * customers.customer_type — native Postgres enum `customer_type`
 * ('company', 'individual'). String-backed values match the PG enum labels
 * exactly (Rails: `enum :customer_type, {company: 'company', individual: 'individual'}`).
 */
enum CustomerType: string
{
    case Company = 'company';
    case Individual = 'individual';
}
