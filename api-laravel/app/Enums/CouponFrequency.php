<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * coupons.frequency — integer column, Rails enum order is the stored
 * value, 0-based (app/models/coupon.rb FREQUENCIES). Never renumber.
 * Same order backs applied_coupons.frequency (app/models/applied_coupon.rb).
 */
enum CouponFrequency: int
{
    case Once = 0;
    case Recurring = 1;
    case Forever = 2;

    /** @return list<string> Rails' FREQUENCIES names, in order. */
    public static function options(): array
    {
        return ['once', 'recurring', 'forever'];
    }

    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'once' => self::Once->value,
            'recurring' => self::Recurring->value,
            'forever' => self::Forever->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Once => 'once',
            self::Recurring => 'recurring',
            self::Forever => 'forever',
        };
    }
}
