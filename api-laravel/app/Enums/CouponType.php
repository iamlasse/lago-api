<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * coupons.coupon_type — integer column, Rails enum order is the stored
 * value, 0-based (app/models/coupon.rb COUPON_TYPES). Never renumber.
 */
enum CouponType: int
{
    case FixedAmount = 0;
    case Percentage = 1;

    /** @return list<string> Rails' Coupon::COUPON_TYPES names, in order. */
    public static function options(): array
    {
        return ['fixed_amount', 'percentage'];
    }

    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'fixed_amount' => self::FixedAmount->value,
            'percentage' => self::Percentage->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::FixedAmount => 'fixed_amount',
            self::Percentage => 'percentage',
        };
    }
}
