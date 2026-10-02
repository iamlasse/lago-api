<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\Plan;
use App\Models\Credit;
use App\Models\Invoice;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Models\AppliedCoupon;
use App\Services\AppliedCoupons\AmountService;

/**
 * Port of Rails' Credits::AppliedCouponService
 * (app/services/credits/applied_coupon_service.rb) — applies ONE applied
 * coupon to the invoice totals and weights the credit over the fees.
 *
 * TODO(port): coupon_targets (limited billable metrics / plans) are not
 * modeled yet — limited coupons fall back to all invoice fees, like
 * unlimited ones.
 */
class AppliedCouponService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly AppliedCoupon $appliedCoupon,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit');
        $customer = $this->invoice->customer;

        if (! CouponLock::held($customer, 'coupon')) {
            return $result->serviceFailure(
                'no_lock_acquired',
                'Calling this service without acquiring a lock is not allowed',
            );
        }

        if (! $this->matchesCurrency()) {
            return $result;
        }

        if ($this->alreadyApplied()) {
            return $result;
        }

        $fees = $this->fees();

        if ($fees->isEmpty()) {
            return $result;
        }

        $creditAmount = AmountService::call(
            appliedCoupon: $this->appliedCoupon,
            baseAmountCents: $this->baseAmountCents($fees),
        )->amount;

        $newCredit = Credit::query()->create([
            'organization_id' => $this->invoice->organization_id,
            'invoice_id' => $this->invoice->id,
            'applied_coupon_id' => $this->appliedCoupon->id,
            'amount_cents' => $creditAmount,
            'amount_currency' => $this->invoice->currency,
            'before_taxes' => true,
        ]);

        // Ensure that base remains the same during weighting process
        $weightingBaseAmountCents = $this->baseAmountCents($fees);

        foreach ($fees as $fee) {
            if ($weightingBaseAmountCents !== 0) {
                $fee->precise_coupons_amount_cents = MoneyMath::add(
                    (string) $fee->precise_coupons_amount_cents,
                    $fee->computePreciseCreditAmountCents($creditAmount, $weightingBaseAmountCents),
                );
            }

            if ((int) $fee->amount_cents < MoneyMath::round((string) $fee->precise_coupons_amount_cents)) {
                $fee->precise_coupons_amount_cents = (string) $fee->amount_cents;
            }

            $fee->save();
        }

        if ($this->appliedCoupon->recurring()) {
            $remaining = max((int) $this->appliedCoupon->frequency_duration_remaining - 1, 0);
            $this->appliedCoupon->frequency_duration_remaining = $remaining;
        }

        if ($this->shouldTerminateAppliedCoupon($creditAmount)) {
            $this->appliedCoupon->markAsTerminated();
        } elseif ($this->appliedCoupon->recurring()) {
            $this->appliedCoupon->save();
        }

        $this->invoice->coupons_amount_cents += $newCredit->amount_cents;
        $this->invoice->sub_total_excluding_taxes_amount_cents -= $newCredit->amount_cents;

        $result->credit = $newCredit;

        return $result;
    }

    private function matchesCurrency(): bool
    {
        if ($this->appliedCoupon->coupon->percentage()) {
            return true;
        }

        return $this->appliedCoupon->amount_currency === $this->invoice->currency;
    }

    private function alreadyApplied(): bool
    {
        return $this->invoice->credits()
            ->where('applied_coupon_id', $this->appliedCoupon->id)
            ->exists();
    }

    private function shouldTerminateAppliedCoupon(int $creditAmount): bool
    {
        if ($this->appliedCoupon->forever()) {
            return false;
        }

        if ($this->appliedCoupon->once()) {
            return $this->appliedCoupon->coupon->percentage()
                || $creditAmount >= $this->appliedCoupon->remainingAmount();
        }

        return (int) $this->appliedCoupon->frequency_duration_remaining <= 0;
    }

    /**
     * TODO: ensure targeted amount is right with BM/plan limitation —
     * coupon_targets are not ported yet, so limited coupons use all fees.
     *
     * @param  iterable<\App\Models\Fee>  $fees
     */
    private function baseAmountCents(iterable $fees): int
    {
        $coupon = $this->appliedCoupon->coupon;

        if ($coupon->limited_billable_metrics || $coupon->limited_plans) {
            $amount = 0;

            foreach ($fees as $fee) {
                $amount += (int) MoneyMath::round($fee->subTotalExcludingTaxesAmountCents());
            }

            return $amount;
        }

        return (int) $this->invoice->sub_total_excluding_taxes_amount_cents;
    }

    private function fees()
    {
        $coupon = $this->appliedCoupon->coupon;

        if ($coupon->limited_billable_metrics) {
            // TODO(port): coupon_targets — billable-metric limited coupons.
            return $this->invoice->fees()->get();
        }

        if ($coupon->limited_plans) {
            // TODO(port): coupon_targets — plan limited coupons (Rails joins
            // fees through subscription → plan on parent_and_overriden_plans).
            $planIds = $coupon->parentAndOverridenPlans()->modelKeys();

            if ($planIds === []) {
                return collect();
            }

            return $this->invoice->fees()
                ->join('subscriptions', function ($join): void {
                    $join->on('subscriptions.id', '=', 'fees.subscription_id');
                })
                ->whereIn('subscriptions.plan_id', $planIds)
                ->select('fees.*')
                ->get();
        }

        return $this->invoice->fees()->get();
    }
}
