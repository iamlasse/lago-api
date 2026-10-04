<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\AppliedCoupon as AppliedCouponModel;

/**
 * Field resolvers for the frozen SDL's `AppliedCoupon` type (port of Rails'
 * Types::AppliedCoupons::Object computed fields). Plain columns resolve
 * through the snake_case attribute fallback.
 */
class AppliedCoupon
{
    /** Rails: the status enum name — the column stores the integer position. */
    public function status(AppliedCouponModel $root): ?string
    {
        return $root->statusEnum()?->label();
    }

    /** Rails: the frequency enum name — the column stores the integer position. */
    public function frequency(AppliedCouponModel $root): ?string
    {
        return $root->frequencyEnum()?->label();
    }

    /** Rails: percentage_rate — the decimal column renders as a Float. */
    public function percentageRate(AppliedCouponModel $root): ?float
    {
        return $root->percentage_rate !== null ? (float) $root->percentage_rate : null;
    }

    /**
     * Rails: amount_cents_remaining — nil for recurring coupons and
     * percentage coupons; otherwise the amount not yet consumed by active
     * credits.
     */
    public function amountCentsRemaining(AppliedCouponModel $root): ?int
    {
        if ($root->recurring()) {
            return null;
        }

        if ($root->coupon->percentage()) {
            return null;
        }

        return (int) $root->amount_cents - $root->activeCreditsAmountCents();
    }
}
