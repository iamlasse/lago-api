<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * contracts.status — native Postgres enum `contract_status`
 * ('pending', 'active', 'terminated', 'canceled'). String-backed values
 * match the PG enum labels exactly (Rails: Contract::STATUSES). Schema
 * default is 'pending'.
 */
enum ContractStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Terminated = 'terminated';
    case Canceled = 'canceled';
}
