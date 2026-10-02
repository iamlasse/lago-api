<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * invoices.status — integer column, Rails enum order is the stored value
 * (app/models/invoice.rb VISIBLE_STATUS + INVISIBLE_STATUS). Never renumber:
 * draft=0, finalized=1, voided=2, generating=3, failed=4, open=5, closed=6,
 * pending=7, deleted=8.
 */
enum InvoiceStatus: int
{
    case Draft = 0;
    case Finalized = 1;
    case Voided = 2;
    case Generating = 3;
    case Failed = 4;
    case Open = 5;
    case Closed = 6;
    case Pending = 7;
    case Deleted = 8;

    /** @return list<string> Rails' Invoice::STATUS names, in order. */
    public static function options(): array
    {
        return ['draft', 'finalized', 'voided', 'generating', 'failed', 'open', 'closed', 'pending', 'deleted'];
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
            'voided' => self::Voided->value,
            'generating' => self::Generating->value,
            'failed' => self::Failed->value,
            'open' => self::Open->value,
            'closed' => self::Closed->value,
            'pending' => self::Pending->value,
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
            self::Voided => 'voided',
            self::Generating => 'generating',
            self::Failed => 'failed',
            self::Open => 'open',
            self::Closed => 'closed',
            self::Pending => 'pending',
            self::Deleted => 'deleted',
        };
    }
}
