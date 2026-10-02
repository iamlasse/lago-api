<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\Invoice;
use App\Services\BaseResult;

/**
 * Port of Rails' Credits::AppliedCouponsService
 * (app/services/credits/applied_coupons_service.rb) — applies all of the
 * customer's active coupons to the invoice, under the coupon advisory lock.
 */
class AppliedCouponsService extends \App\Services\BaseService
{
    public function __construct(private readonly Invoice $invoice) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credits', 'invoice');
        $customer = $this->invoice->customer;

        $appliedCoupons = $this->appliedCoupons($customer->id);

        if ($appliedCoupons->isEmpty()) {
            return $result;
        }

        if ((int) $this->invoice->fees_amount_cents === 0) {
            return $result;
        }

        $result->credits = [];

        // Take an advisory lock on the coupons for this customer. We're not
        // locking individual coupons as that might lead to deadlocks.
        CouponLock::withLock($customer, 'coupon', function () use ($appliedCoupons, $result): void {
            // reload coupons now that we've acquired the lock
            $appliedCoupons->each->refresh();

            foreach ($appliedCoupons as $appliedCoupon) {
                if ((int) $this->invoice->sub_total_excluding_taxes_amount_cents <= 0) {
                    break;
                }

                $creditResult = AppliedCouponService::call(
                    invoice: $this->invoice,
                    appliedCoupon: $appliedCoupon,
                );

                try {
                    $creditResult->raiseIfError();
                } catch (\App\Services\Failures\FailedResult $e) {
                    $result->failWithError($e);

                    return;
                }

                $result->credits = array_merge($result->credits ?? [], [$creditResult->credit]);
            }
        });

        $result->invoice = $this->invoice;

        return $result;
    }

    /**
     * NOTE: We want to apply first coupons limited to the billable metrics,
     * then the ones limited to the plans and finally the ones with no
     * limitation.
     */
    private function appliedCoupons(string $customerId)
    {
        return \App\Models\AppliedCoupon::query()
            ->active()
            ->where('customer_id', $customerId)
            ->join('coupons', 'coupons.id', '=', 'applied_coupons.coupon_id')
            ->latest('coupons.limited_billable_metrics')
            ->latest('coupons.limited_plans')
            ->orderBy('applied_coupons.created_at')
            ->select('applied_coupons.*')
            ->get();
    }
}
