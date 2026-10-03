<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * credit_notes.reason — integer column, Rails enum order is the stored value
 * (app/models/credit_note.rb REASON). Never renumber: duplicated_charge=0,
 * product_unsatisfactory=1, order_change=2, order_cancellation=3,
 * fraudulent_charge=4, other=5.
 */
enum CreditNoteReason: int
{
    case DuplicatedCharge = 0;
    case ProductUnsatisfactory = 1;
    case OrderChange = 2;
    case OrderCancellation = 3;
    case FraudulentCharge = 4;
    case Other = 5;

    /** @return list<string> Rails' CreditNote::REASON names, in order. */
    public static function options(): array
    {
        return [
            'duplicated_charge',
            'product_unsatisfactory',
            'order_change',
            'order_cancellation',
            'fraudulent_charge',
            'other',
        ];
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
            'duplicated_charge' => self::DuplicatedCharge->value,
            'product_unsatisfactory' => self::ProductUnsatisfactory->value,
            'order_change' => self::OrderChange->value,
            'order_cancellation' => self::OrderCancellation->value,
            'fraudulent_charge' => self::FraudulentCharge->value,
            'other' => self::Other->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::DuplicatedCharge => 'duplicated_charge',
            self::ProductUnsatisfactory => 'product_unsatisfactory',
            self::OrderChange => 'order_change',
            self::OrderCancellation => 'order_cancellation',
            self::FraudulentCharge => 'fraudulent_charge',
            self::Other => 'other',
        };
    }
}
