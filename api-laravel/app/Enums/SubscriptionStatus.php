<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * subscriptions.status — integer column, Rails enum order is the stored
 * value, 0-based (app/models/subscription.rb STATUSES). Never renumber:
 * 4 (incomplete) is live, only its position in history matters.
 */
enum SubscriptionStatus: int
{
    case Pending = 0;
    case Active = 1;
    case Terminated = 2;
    case Canceled = 3;
    case Incomplete = 4;

    /** @return list<string> Rails' Subscription::STATUSES names, in order. */
    public static function options(): array
    {
        return ['pending', 'active', 'terminated', 'canceled', 'incomplete'];
    }

    /**
     * Rails assigns the enum NAME ("pending" | …) and the column stores the
     * integer position; returns the position, or null when the name is not
     * one of the options.
     */
    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'pending' => self::Pending->value,
            'active' => self::Active->value,
            'terminated' => self::Terminated->value,
            'canceled' => self::Canceled->value,
            'incomplete' => self::Incomplete->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Active => 'active',
            self::Terminated => 'terminated',
            self::Canceled => 'canceled',
            self::Incomplete => 'incomplete',
        };
    }
}
