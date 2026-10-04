<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use Illuminate\Support\Collection;
use App\Models\Coupon as CouponModel;

/**
 * Field resolvers for the frozen SDL's `Coupon` type (port of Rails'
 * Types::Coupons::Object computed fields). Plain columns resolve through
 * the snake_case attribute fallback; the activity_logs field keeps the null
 * fallback documented in graphql/FULL_SCHEMA_NOTES.md.
 */
class Coupon
{
    /** Rails: the coupon_type enum name — the column stores the integer position. */
    public function couponType(CouponModel $root): ?string
    {
        return $root->typeEnum()?->label();
    }

    /** Rails: the expiration enum name — the column stores the integer position. */
    public function expiration(CouponModel $root): ?string
    {
        return $root->expirationEnum()?->label();
    }

    /** Rails: the frequency enum name — the column stores the integer position. */
    public function frequency(CouponModel $root): ?string
    {
        return $root->frequencyEnum()?->label();
    }

    /** Rails: the status enum name — the column stores the integer position. */
    public function status(CouponModel $root): ?string
    {
        return $root->statusEnum()?->label();
    }

    /** Rails: percentage_rate — the decimal column renders as a Float. */
    public function percentageRate(CouponModel $root): ?float
    {
        return $root->percentage_rate !== null ? (float) $root->percentage_rate : null;
    }

    /** Rails: applied_coupons.count — every applied coupon, terminated included. */
    public function appliedCouponsCount(CouponModel $root): int
    {
        return $root->appliedCoupons()->count();
    }

    /** Rails: applied_coupons.active.select(:customer_id).distinct.count. */
    public function customersCount(CouponModel $root): int
    {
        return $root->appliedCoupons()
            ->active()
            ->select('applied_coupons.customer_id')
            ->distinct()
            ->count('applied_coupons.customer_id');
    }

    /** Rails: object.plans.parents — parent plans only, overrides excluded. */
    public function plans(CouponModel $root): Collection
    {
        return $root->plans()->parents()->get();
    }

    // -- Unported features (stubs, see FULL_SCHEMA_NOTES.md) -------------------

    public function activityLogs(CouponModel $root): ?array
    {
        return null;
    }
}
