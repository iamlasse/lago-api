<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * invoices.invoice_type — integer column, Rails enum order is the stored
 * value, 0-based (app/models/invoice.rb INVOICE_TYPES). Never renumber.
 */
enum InvoiceType: int
{
    case Subscription = 0;
    case AddOn = 1;
    case Credit = 2;
    case OneOff = 3;
    case AdvanceCharges = 4;
    case ProgressiveBilling = 5;

    /** @return list<string> Rails' Invoice::INVOICE_TYPES names, in order. */
    public static function options(): array
    {
        return ['subscription', 'add_on', 'credit', 'one_off', 'advance_charges', 'progressive_billing'];
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
            'subscription' => self::Subscription->value,
            'add_on' => self::AddOn->value,
            'credit' => self::Credit->value,
            'one_off' => self::OneOff->value,
            'advance_charges' => self::AdvanceCharges->value,
            'progressive_billing' => self::ProgressiveBilling->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'subscription',
            self::AddOn => 'add_on',
            self::Credit => 'credit',
            self::OneOff => 'one_off',
            self::AdvanceCharges => 'advance_charges',
            self::ProgressiveBilling => 'progressive_billing',
        };
    }
}
