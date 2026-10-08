<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * recurring_transaction_rules.interval — integer column, Rails enum order is
 * the stored value (app/models/recurring_transaction_rule.rb INTERVALS).
 * Never renumber: weekly=0, monthly=1, quarterly=2, yearly=3, semiannual=4
 * (Rails' array order — yearly BEFORE semiannual).
 */
enum RecurringTransactionInterval: int
{
    case Weekly = 0;
    case Monthly = 1;
    case Quarterly = 2;
    case Yearly = 3;
    case Semiannual = 4;

    /** @return list<string> Rails' INTERVALS names, in order. */
    public static function options(): array
    {
        return ['weekly', 'monthly', 'quarterly', 'yearly', 'semiannual'];
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
            'weekly' => self::Weekly->value,
            'monthly' => self::Monthly->value,
            'quarterly' => self::Quarterly->value,
            'yearly' => self::Yearly->value,
            'semiannual' => self::Semiannual->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'weekly',
            self::Monthly => 'monthly',
            self::Quarterly => 'quarterly',
            self::Yearly => 'yearly',
            self::Semiannual => 'semiannual',
        };
    }
}
