<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * wallet_transactions.transaction_type — integer column, Rails enum order
 * is the stored value (app/models/wallet_transaction.rb
 * TRANSACTION_TYPES). Never renumber: inbound=0, outbound=1.
 */
enum WalletTransactionType: int
{
    case Inbound = 0;
    case Outbound = 1;

    /** @return list<string> Rails' TRANSACTION_TYPES names, in order. */
    public static function options(): array
    {
        return ['inbound', 'outbound'];
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
            'inbound' => self::Inbound->value,
            'outbound' => self::Outbound->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'inbound',
            self::Outbound => 'outbound',
        };
    }
}
