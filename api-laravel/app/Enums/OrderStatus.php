<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * orders.status — native Postgres enum `order_status` (the column stores the
 * label string; Rails: app/models/order.rb STATUSES). Never rename the
 * stored values.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Executed = 'executed';
    case Failed = 'failed';

    /** @return list<string> Rails' Order::STATUSES values, in order. */
    public static function options(): array
    {
        return ['created', 'executed', 'failed'];
    }

    /**
     * Rails assigns the enum symbol; the column stores the label string.
     * Returns the label string, or null when the value is not one of the
     * options (Rails' `validate: true` inclusion check).
     */
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
