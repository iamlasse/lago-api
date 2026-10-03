<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;
use App\Services\AppliedCoupons\TerminateService;

/**
 * Port of Rails' Coupons::DestroyService
 * (app/services/coupons/destroy_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity_loggable middleware (action: "coupon.deleted").
 */
class DestroyService extends BaseService
{
    public function __construct(
        private readonly ?Coupon $coupon,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('coupon');

        if ($this->coupon === null) {
            return $result->notFoundFailure('coupon');
        }

        DB::transaction(function (): void {
            // Rails: coupon.discard! — a soft delete on deleted_at.
            $this->coupon->delete();

            CouponTarget::query()
                ->where('coupon_id', $this->coupon->id)
                ->update(['deleted_at' => now()]);

            $this->coupon->appliedCoupons()
                ->active()
                ->get()
                ->each(static fn ($appliedCoupon) => TerminateService::call(
                    appliedCoupon: $appliedCoupon,
                ));
        });

        $result->coupon = $this->coupon;

        return $result;
    }
}
