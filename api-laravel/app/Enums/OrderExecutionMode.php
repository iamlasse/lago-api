<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * orders.execution_mode — native Postgres enum `order_execution_mode`, NULL
 * until the order form is signed (Rails: app/models/order.rb
 * EXECUTION_MODES). Never rename the stored values.
 */
enum OrderExecutionMode: string
{
    case ExecuteInLago = 'execute_in_lago';
    case OrderOnly = 'order_only';

    /** @return list<string> Rails' Order::EXECUTION_MODES values, in order. */
    public static function options(): array
    {
        return ['execute_in_lago', 'order_only'];
    }

    public static function fromOption(mixed $value): ?string
    {
        return in_array($value, self::options(), true) ? $value : null;
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return $this->value;
    }
}
