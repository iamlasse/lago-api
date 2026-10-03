<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * wallet_transactions.transaction_status — integer column, Rails enum order
 * is the stored value (app/models/wallet_transaction.rb
 * TRANSACTION_STATUSES). Never renumber: purchased=0, granted=1, voided=2,
 * invoiced=3.
 *
 * Named "CreditStatus" after the column's semantic (what happened to the
 * credit); the API key stays `transaction_status`.
 */
enum WalletTransactionCreditStatus: int
{
    case Purchased = 0;
    case Granted = 1;
    case Voided = 2;
    case Invoiced = 3;

    /** @return list<string> Rails' TRANSACTION_STATUSES names, in order. */
    public static function options(): array
    {
        return ['purchased', 'granted', 'voided', 'invoiced'];
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
            'purchased' => self::Purchased->value,
            'granted' => self::Granted->value,
            'voided' => self::Voided->value,
            'invoiced' => self::Invoiced->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Purchased => 'purchased',
            self::Granted => 'granted',
            self::Voided => 'voided',
            self::Invoiced => 'invoiced',
        };
    }
}
