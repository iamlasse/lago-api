<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * wallet_transactions.status — integer column, Rails enum order is the
 * stored value (app/models/wallet_transaction.rb STATUSES). Never renumber:
 * pending=0, settled=1, failed=2.
 */
enum WalletTransactionStatus: int
{
    case Pending = 0;
    case Settled = 1;
    case Failed = 2;

    /** @return list<string> Rails' STATUSES names, in order. */
    public static function options(): array
    {
        return ['pending', 'settled', 'failed'];
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
            'pending' => self::Pending->value,
            'settled' => self::Settled->value,
            'failed' => self::Failed->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Settled => 'settled',
            self::Failed => 'failed',
        };
    }
}
