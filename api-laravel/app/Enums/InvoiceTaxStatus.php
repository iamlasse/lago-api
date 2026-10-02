<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * invoices.tax_status — native Postgres enum `tax_status` (string-backed),
 * values per Rails' Invoice::TAX_STATUSES (app/models/invoice.rb).
 */
enum InvoiceTaxStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** @return list<string> Rails' Invoice::TAX_STATUSES names, in order. */
    public static function options(): array
    {
        return ['pending', 'succeeded', 'failed'];
    }

    public static function fromOption(mixed $value): ?string
    {
        if (is_string($value) && self::tryFrom($value) !== null) {
            return $value;
        }

        return null;
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return $this->value;
    }
}
