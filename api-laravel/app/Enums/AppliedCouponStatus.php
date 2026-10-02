<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * applied_coupons.status — integer column, Rails enum order is the stored
 * value, 0-based (app/models/applied_coupon.rb STATUSES). Never renumber.
 */
enum AppliedCouponStatus: int
{
    case Active = 0;
    case Terminated = 1;

    /** @return list<string> Rails' AppliedCoupon::STATUSES names, in order. */
    public static function options(): array
    {
        return ['active', 'terminated'];
    }

    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'active' => self::Active->value,
            'terminated' => self::Terminated->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'active',
            self::Terminated => 'terminated',
        };
    }
}
