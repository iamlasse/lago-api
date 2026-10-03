<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * credit_notes.refund_status — integer column, Rails enum order is the
 * stored value (app/models/credit_note.rb REFUND_STATUS). Never renumber:
 * pending=0, succeeded=1, failed=2.
 *
 * Status of the refund part:
 * - pending: the refund is pending for its execution
 * - succeeded: the refund has been executed
 * - failed: the refund process has failed
 */
enum CreditNoteRefundStatus: int
{
    case Pending = 0;
    case Succeeded = 1;
    case Failed = 2;

    /** @return list<string> Rails' CreditNote::REFUND_STATUS names, in order. */
    public static function options(): array
    {
        return ['pending', 'succeeded', 'failed'];
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
