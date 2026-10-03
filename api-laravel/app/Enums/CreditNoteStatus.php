<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * credit_notes.status — integer column, Rails enum order is the stored value
 * (app/models/credit_note.rb STATUS). Never renumber: draft=0, finalized=1,
 * deleted=2.
 */
enum CreditNoteStatus: int
{
    case Draft = 0;
    case Finalized = 1;
    case Deleted = 2;

    /** @return list<string> Rails' CreditNote::STATUS names, in order. */
    public static function options(): array
    {
        return ['draft', 'finalized', 'deleted'];
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
            'draft' => self::Draft->value,
            'finalized' => self::Finalized->value,
            'deleted' => self::Deleted->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Finalized => 'finalized',
            self::Deleted => 'deleted',
        };
    }
}
