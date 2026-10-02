<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * coupons.expiration — integer column, Rails enum order is the stored
 * value, 0-based (app/models/coupon.rb EXPIRATION_TYPES). Never renumber.
 */
enum CouponExpiration: int
{
    case NoExpiration = 0;
    case TimeLimit = 1;

    /** @return list<string> Rails' Coupon::EXPIRATION_TYPES names, in order. */
    public static function options(): array
    {
        return ['no_expiration', 'time_limit'];
    }

    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'no_expiration' => self::NoExpiration->value,
            'time_limit' => self::TimeLimit->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::NoExpiration => 'no_expiration',
            self::TimeLimit => 'time_limit',
        };
    }
}
