<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * credit_notes.credit_status — integer column, Rails enum order is the
 * stored value (app/models/credit_note.rb CREDIT_STATUS). Never renumber:
 * available=0, consumed=1, voided=2.
 *
 * Status of the credit part:
 * - available: a credit amount remains available
 * - consumed: the credit amount was totally consumed
 * - voided: the credit note was voided
 */
enum CreditNoteCreditStatus: int
{
    case Available = 0;
    case Consumed = 1;
    case Voided = 2;

    /** @return list<string> Rails' CreditNote::CREDIT_STATUS names, in order. */
    public static function options(): array
    {
        return ['available', 'consumed', 'voided'];
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
            'available' => self::Available->value,
            'consumed' => self::Consumed->value,
            'voided' => self::Voided->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'available',
            self::Consumed => 'consumed',
            self::Voided => 'voided',
        };
    }
}
