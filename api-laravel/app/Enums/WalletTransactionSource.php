<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * wallet_transactions.source — integer column, Rails enum order is the
 * stored value (app/models/wallet_transaction.rb SOURCES). Never renumber:
 * manual=0, interval=1, threshold=2.
 */
enum WalletTransactionSource: int
{
    case Manual = 0;
    case Interval = 1;
    case Threshold = 2;

    /** @return list<string> Rails' SOURCES names, in order. */
    public static function options(): array
    {
        return ['manual', 'interval', 'threshold'];
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
            'manual' => self::Manual->value,
            'interval' => self::Interval->value,
            'threshold' => self::Threshold->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'manual',
            self::Interval => 'interval',
            self::Threshold => 'threshold',
        };
    }
}
