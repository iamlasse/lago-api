<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * recurring_transaction_rules.trigger — integer column, Rails enum order is
 * the stored value (app/models/recurring_transaction_rule.rb TRIGGERS).
 * Never renumber: interval=0, threshold=1.
 */
enum RecurringTransactionTrigger: int
{
    case Interval = 0;
    case Threshold = 1;

    /** @return list<string> Rails' TRIGGERS names, in order. */
    public static function options(): array
    {
        return ['interval', 'threshold'];
    }

    /**
     * Rails assigns the enum NAME and the column stores the integer
     * position; returns the position, or null when the name is not one of
     * the options.
     */
    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'interval' => self::Interval->value,
            'threshold' => self::Threshold->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Interval => 'interval',
            self::Threshold => 'threshold',
        };
    }
}
