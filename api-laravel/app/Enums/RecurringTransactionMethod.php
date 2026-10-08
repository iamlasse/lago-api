<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * recurring_transaction_rules.method — integer column, Rails enum order is
 * the stored value (app/models/recurring_transaction_rule.rb METHODS).
 * Never renumber: fixed=0, target=1.
 */
enum RecurringTransactionMethod: int
{
    case Fixed = 0;
    case Target = 1;

    /** @return list<string> Rails' METHODS names, in order. */
    public static function options(): array
    {
        return ['fixed', 'target'];
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
            'fixed' => self::Fixed->value,
            'target' => self::Target->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'fixed',
            self::Target => 'target',
        };
    }
}
