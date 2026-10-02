<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * plans.interval — integer column, Rails enum order is the stored value,
 * 0-based (app/models/plan.rb INTERVALS). Never renumber.
 *
 * NOTE: this order differs from the one in older Lago docs / GraphQL enums —
 * Rails' comment on Plan::INTERVALS is authoritative (weekly, monthly,
 * yearly, quarterly, semiannual).
 */
enum PlanInterval: int
{
    case Weekly = 0;
    case Monthly = 1;
    case Yearly = 2;
    case Quarterly = 3;
    case Semiannual = 4;

    /** @return list<string> Rails' Plan::INTERVALS names, in order. */
    public static function options(): array
    {
        return ['weekly', 'monthly', 'yearly', 'quarterly', 'semiannual'];
    }

    /**
     * Rails assigns the enum NAME ("weekly" | … | "semiannual") and the
     * column stores the integer position; returns the position, or null when
     * the name is not one of the options.
     */
    public static function fromOption(mixed $value): ?int
    {
        if (is_int($value)) {
            return self::tryFrom($value) !== null ? $value : null;
        }

        return match (is_string($value) ? mb_strtolower($value) : null) {
            'weekly' => self::Weekly->value,
            'monthly' => self::Monthly->value,
            'yearly' => self::Yearly->value,
            'quarterly' => self::Quarterly->value,
            'semiannual' => self::Semiannual->value,
            default => null,
        };
    }

    /** The Rails enum name (the string the REST API emits for the value). */
    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'weekly',
            self::Monthly => 'monthly',
            self::Yearly => 'yearly',
            self::Quarterly => 'quarterly',
            self::Semiannual => 'semiannual',
        };
    }
}
