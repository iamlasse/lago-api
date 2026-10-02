<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * fees.fee_type — integer column, Rails enum order is the stored value,
 * 0-based (app/models/fee.rb FEE_TYPES). Never renumber.
 */
enum FeeType: int
{
    case Charge = 0;
    case AddOn = 1;
    case Subscription = 2;
    case Credit = 3;
    case Commitment = 4;
    case FixedCharge = 5;
    case Product = 6;

    /** @return list<string> Rails' Fee::FEE_TYPES names, in order. */
    public static function options(): array
    {
        return ['charge', 'add_on', 'subscription', 'credit', 'commitment', 'fixed_charge', 'product'];
    }

    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'charge' => self::Charge->value,
            'add_on' => self::AddOn->value,
            'subscription' => self::Subscription->value,
            'credit' => self::Credit->value,
            'commitment' => self::Commitment->value,
            'fixed_charge' => self::FixedCharge->value,
            'product' => self::Product->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Charge => 'charge',
            self::AddOn => 'add_on',
            self::Subscription => 'subscription',
            self::Credit => 'credit',
            self::Commitment => 'commitment',
            self::FixedCharge => 'fixed_charge',
            self::Product => 'product',
        };
    }
}
