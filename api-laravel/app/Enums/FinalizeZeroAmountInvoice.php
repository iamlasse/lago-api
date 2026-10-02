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

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return mb_strtolower($this->name);
    }
}
