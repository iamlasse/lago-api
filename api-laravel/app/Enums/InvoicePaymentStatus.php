<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * invoices.payment_status — integer column, Rails enum order is the stored
 * value, 0-based (app/models/invoice.rb PAYMENT_STATUS). Never renumber.
 */
enum InvoicePaymentStatus: int
{
    case Pending = 0;
    case Succeeded = 1;
    case Failed = 2;

    /** @return list<string> Rails' Invoice::PAYMENT_STATUS names, in order. */
    public static function options(): array
    {
        return ['pending', 'succeeded', 'failed'];
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
        };
    }
}
