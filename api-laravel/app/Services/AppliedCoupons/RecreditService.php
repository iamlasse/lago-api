<?php

declare(strict_types=1);

namespace App\Services\AppliedCoupons;

use App\Models\Credit;
use App\Services\BaseResult;
use App\Models\AppliedCoupon;
use App\Services\BaseService;
use App\Enums\AppliedCouponStatus;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' AppliedCoupons::RecreditService
 * (app/services/applied_coupons/recredit_service.rb) — gives back the
 * coupon consumption of a credit whose invoice is voided.
 *
 * Call site: Rails' Invoices::VoidService calls this for every coupon
 * credit; the Laravel VoidService still carries a TODO(port) at that point.
 */
class RecreditService extends BaseService
{
    public function __construct(
        private readonly Credit $credit,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_coupon');

        $appliedCoupon = $this->credit->appliedCoupon;

        if ($appliedCoupon === null) {
            return $result->notFoundFailure('applied_coupon');
        }

        try {
            $this->recredit($result, $appliedCoupon);
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }

        $result->applied_coupon = $appliedCoupon;

        return $result;
    }

    private function recredit(BaseResult $result, AppliedCoupon $appliedCoupon): void
    {
        // Rails: applied_coupon.with_lock — reload under a row lock inside
        // a transaction.
        DB::transaction(function () use ($result, $appliedCoupon): void {
            $locked = AppliedCoupon::query()
                ->whereKey($appliedCoupon->getKey())
                ->lockForUpdate()
                ->first();

            // If the coupon was terminated and this was the last credit that
            // caused it to be terminated, reactivate the coupon.
            if ($locked->isTerminated() && $this->shouldReactivateCoupon($locked)) {
                $locked->status = AppliedCouponStatus::Active;
                $locked->terminated_at = null;
                $this->saveOrFail($result, $locked);
            }

            // For recurring coupons, increment the frequency_duration_remaining.
            if ($locked->recurring()) {
                $locked->frequency_duration_remaining = (int) $locked->frequency_duration_remaining + 1;
                $this->saveOrFail($result, $locked);
            }
        });
    }

    private function shouldReactivateCoupon(AppliedCoupon $appliedCoupon): bool
    {
        // Forever coupons don't need to be reactivated.
        if ($appliedCoupon->forever()) {
            return false;
        }

        // Check if the original coupon is still active.
        if ($appliedCoupon->coupon->isTerminated()) {
            return false;
        }

        // For both once and recurring coupons, we can reactivate them if
        // they're terminated since they would have been terminated due to
        // usage.
        return true;
    }

    /**
     * Rails: `save!` runs the record validations; RecordInvalid is rescued
     * into a record_validation_failure.
     */
    private function saveOrFail(BaseResult $result, AppliedCoupon $appliedCoupon): void
    {
        $errors = $appliedCoupon->validateAttributes();

        if ($errors !== []) {
            $result->recordValidationFailure($errors)->raiseIfError();
        }

        $appliedCoupon->save();
    }
}
