<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * fees.payment_status — integer column, Rails enum order is the stored
 * value, 0-based (app/models/fee.rb PAYMENT_STATUS). Never renumber.
 * Note: fees have a fourth state (`refunded`) invoices do not.
 */
enum FeePaymentStatus: int
{
    case Pending = 0;
    case Succeeded = 1;
    case Failed = 2;
    case Refunded = 3;

    /** @return list<string> Rails' Fee::PAYMENT_STATUS names, in order. */
    public static function options(): array
    {
        return ['pending', 'succeeded', 'failed', 'refunded'];
    }

    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'pending' => self::Pending->value,
            'succeeded' => self::Succeeded->value,
            'failed' => self::Failed->value,
            'refunded' => self::Refunded->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Succeeded => 'succeeded',
            self::Failed => 'failed',
            self::Refunded => 'refunded',
        };
    }
}
