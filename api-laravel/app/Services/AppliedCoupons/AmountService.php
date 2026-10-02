<?php

declare(strict_types=1);

namespace App\Services\AppliedCoupons;

use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Models\AppliedCoupon;

/**
 * Port of Rails' AppliedCoupons::AmountService
 * (app/services/applied_coupons/amount_service.rb) — the coupon amount to
 * apply on a base amount, by coupon type and frequency.
 */
class AmountService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?AppliedCoupon $appliedCoupon,
        private readonly int $baseAmountCents,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('amount');

        if ($this->appliedCoupon === null) {
            return $result->notFoundFailure('applied_coupon');
        }

        $result->amount = $this->computeAmount();

        return $result;
    }

    private function computeAmount(): int
    {
        $coupon = $this->appliedCoupon->coupon;

        if ($coupon->percentage()) {
            $discountedValue = MoneyMath::mul(
                (string) $this->baseAmountCents,
                MoneyMath::fdiv((string) $this->appliedCoupon->percentage_rate, '100'),
            );

            return MoneyMath::compare($discountedValue, (string) $this->baseAmountCents) >= 0
                ? $this->baseAmountCents
                : MoneyMath::round($discountedValue);
        }

        if ($this->appliedCoupon->recurring() || $this->appliedCoupon->forever()) {
            return $this->appliedCoupon->amount_cents > $this->baseAmountCents
                ? $this->baseAmountCents
                : $this->appliedCoupon->amount_cents;
        }

        return $this->appliedCoupon->remainingAmount() > $this->baseAmountCents
            ? $this->baseAmountCents
            : $this->appliedCoupon->remainingAmount();
    }
}
