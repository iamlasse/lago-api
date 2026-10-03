<?php

declare(strict_types=1);

namespace App\Services\AppliedCoupons;

use App\Services\BaseResult;
use App\Models\AppliedCoupon;
use App\Services\BaseService;

/**
 * Port of Rails' AppliedCoupons::TerminateService
 * (app/services/applied_coupons/terminate_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): Utils::ActivityLog.produce(applied_coupon,
 *   "applied_coupon.deleted").
 */
class TerminateService extends BaseService
{
    public function __construct(
        private readonly ?AppliedCoupon $appliedCoupon,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_coupon');

        if ($this->appliedCoupon === null) {
            return $result->notFoundFailure('applied_coupon');
        }

        if (! $this->appliedCoupon->isTerminated()) {
            // Rails: mark_as_terminated! runs the record validations and
            // raises RecordInvalid on failure.
            $errors = $this->appliedCoupon->validateAttributes();

            if ($errors !== []) {
                return $result->recordValidationFailure($errors);
            }

            $this->appliedCoupon->markAsTerminated();
        }

        // TODO(port): Utils::ActivityLog.produce(
        //   applied_coupon, "applied_coupon.deleted").

        $result->applied_coupon = $this->appliedCoupon;

        return $result;
    }
}
