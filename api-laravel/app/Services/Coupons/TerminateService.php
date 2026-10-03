<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Models\Coupon;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Coupons::TerminateService
 * (app/services/coupons/terminate_service.rb).
 */
class TerminateService extends BaseService
{
    public function __construct(
        private readonly ?Coupon $coupon,
    ) {
        parent::__construct();
    }

    /** Rails: `self.terminate_all_expired`. */
    public static function terminateAllExpired(): void
    {
        Coupon::query()
            ->active()
            ->timeLimit()
            ->expired()
            ->get()
            ->each(static fn (Coupon $coupon) => $coupon->markAsTerminated());
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('coupon');

        if ($this->coupon === null) {
            return $result->notFoundFailure('coupon');
        }

        if (! $this->coupon->isTerminated()) {
            // Rails: coupon.mark_as_terminated! runs the record validations
            // and raises RecordInvalid on failure.
            $errors = $this->coupon->validateAttributes();

            if ($errors !== []) {
                return $result->recordValidationFailure($errors);
            }

            $this->coupon->markAsTerminated();
        }

        $result->coupon = $this->coupon;

        return $result;
    }
}
