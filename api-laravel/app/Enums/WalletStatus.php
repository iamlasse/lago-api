<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * wallets.status — integer column, Rails enum order is the stored value
 * (app/models/wallet.rb STATUSES). Never renumber: active=0, terminated=1.
 */
enum WalletStatus: int
{
    case Active = 0;
    case Terminated = 1;

    /** @return list<string> Rails' Wallet::STATUSES names, in order. */
    public static function options(): array
    {
        return ['active', 'terminated'];
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
            'active' => self::Active->value,
            'terminated' => self::Terminated->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'active',
            self::Terminated => 'terminated',
        };
    }
}
