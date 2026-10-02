<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * subscriptions.billing_time — integer column, Rails enum order is the
 * stored value, 0-based (app/models/subscription.rb BILLING_TIME).
 * Never renumber. Schema default is 0 (calendar).
 */
enum BillingTime: int
{
    case Calendar = 0;
    case Anniversary = 1;

    /** @return list<string> Rails' Subscription::BILLING_TIME names, in order. */
    public static function options(): array
    {
        return ['calendar', 'anniversary'];
    }

    /**
     * Rails assigns the enum NAME ("calendar" | "anniversary") and the column
     * stores the integer position; returns the position, or null when the
     * name is not one of the options.
     */
    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'calendar' => self::Calendar->value,
            'anniversary' => self::Anniversary->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Calendar => 'calendar',
            self::Anniversary => 'anniversary',
        };
    }
}
