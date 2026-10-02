<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * customers.finalize_zero_amount_invoice — integer column, Rails enum order
 * is the stored value, 0-based. Never renumber.
 */
enum FinalizeZeroAmountInvoice: int
{
    case Inherit = 0;
    case Skip = 1;
    case Finalize = 2;

    /** @return list<string> Rails' Customer::FINALIZE_ZERO_AMOUNT_INVOICE_OPTIONS. */
    public static function options(): array
    {
        return ['inherit', 'skip', 'finalize'];
    }

    /**
     * Rails assigns the enum NAME ("inherit" | "skip" | "finalize") and the
     * column stores the integer position; returns the position, or null when
     * the name is not one of the options.
     */
    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return in_array($value, [0, 1, 2], true) ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'inherit' => self::Inherit->value,
            'skip' => self::Skip->value,
            'finalize' => self::Finalize->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return \Illuminate\Support\Str::snake($this->name);
    }
}
