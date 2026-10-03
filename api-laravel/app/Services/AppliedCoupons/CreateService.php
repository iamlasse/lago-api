<?php

declare(strict_types=1);

namespace App\Services\AppliedCoupons;

use App\Models\Charge;
use App\Models\Coupon;
use App\Models\Customer;
use App\Enums\CouponStatus;
use App\Models\CouponTarget;
use App\Services\BaseResult;
use App\Models\AppliedCoupon;
use App\Services\BaseService;
use App\Enums\AppliedCouponStatus;
use Illuminate\Support\Facades\DB;
use App\Services\Credits\CouponLock;
use App\Services\Failures\FailedResult;
use App\Services\Customers\UpdateCurrencyService;

/**
 * Port of Rails' AppliedCoupons::CreateService
 * (app/services/applied_coupons/create_service.rb).
 *
 * Rails takes the advisory lock through Customers::LockService
 * (scope: :coupon); that namespace is not ported yet, so the lock is taken
 * through the drop-in Credits\CouponLock (same lock key string and
 * hashtext hashing — see its TODO(port) note).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity_loggable middleware (action: "applied_coupon.created").
 */
class CreateService extends BaseService
{
    public function __construct(
        private readonly ?Customer $customer,
        private readonly ?Coupon $coupon,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('applied_coupon');

        if ($this->customer === null) {
            return $result->notFoundFailure('customer');
        }

        try {
            // Rails: Customers::LockService defaults to transaction: true —
            // the advisory xact lock lives inside a wrapping transaction.
            DB::transaction(function () use ($result): void {
                CouponLock::withLock($this->customer, 'coupon', function () use ($result): void {
                    $this->checkPreconditions($result);

                    if ($result->failure()) {
                        return;
                    }

                    $result->applied_coupon = $this->applyCoupon($result);
                });
            });

            return $result;
        } catch (FailedResult $e) {
            return $this->embedFailure($result, $e);
        }
    }

    /**
     * Rails assigns numbers to the decimal column freely; the BcNumeric
     * cast only accepts plain numeric strings, so scalar amounts are
     * stringified at the service boundary.
     */
    private static function numericArg(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function applyCoupon(BaseResult $result): AppliedCoupon
    {
        $coupon = $this->coupon;
        $params = $this->params;

        $appliedCoupon = new AppliedCoupon([
            'customer_id' => $this->customer->id,
            'coupon_id' => $coupon->id,
            'organization_id' => $this->customer->organization_id,
            'amount_cents' => $params['amount_cents'] ?? $coupon->amount_cents,
            'amount_currency' => $params['amount_currency'] ?? $coupon->amount_currency,
            'percentage_rate' => self::numericArg($params['percentage_rate'] ?? $coupon->percentage_rate),
            'frequency' => $params['frequency'] ?? $coupon->frequencyEnum()?->value,
            'frequency_duration' => $params['frequency_duration'] ?? $coupon->frequency_duration,
            'frequency_duration_remaining' => $params['frequency_duration'] ?? $coupon->frequency_duration,
        ]);

        DB::transaction(function () use ($appliedCoupon, $result, $params, $coupon): void {
            if ($coupon->fixedAmount()) {
                UpdateCurrencyService::call(
                    customer: $this->customer,
                    currency: $params['amount_currency'] ?? $coupon->amount_currency,
                )->raiseIfError();
            }

            $errors = $appliedCoupon->validateAttributes();

            if ($errors !== []) {
                $result->recordValidationFailure($errors)->raiseIfError();
            }

            $appliedCoupon->save();
        });

        return $appliedCoupon;
    }

    private function checkPreconditions(BaseResult $result): void
    {
        $coupon = $this->coupon;

        if ($coupon === null || $coupon->statusEnum() !== CouponStatus::Active) {
            $result->notFoundFailure('coupon');

            return;
        }

        if ($this->planLimitationOverlapping()) {
            $result->notAllowedFailure('plan_overlapping');

            return;
        }

        if (! $this->reusableCoupon()) {
            $result->singleValidationFailure('coupon_is_not_reusable', 'coupon');
        }
    }

    private function reusableCoupon(): bool
    {
        if ($this->coupon->reusable) {
            return true;
        }

        return ! $this->customer->appliedCoupons()
            ->where('coupon_id', $this->coupon->id)
            ->exists();
    }

    private function planLimitationOverlapping(): bool
    {
        $coupon = $this->coupon;

        if (! $coupon->limited_plans && ! $coupon->limited_billable_metrics) {
            return false;
        }

        $couponPlanTargets = CouponTarget::query()
            ->where('coupon_id', $coupon->id)
            ->whereNotNull('plan_id')
            ->select('plan_id');

        $couponBillableMetricTargets = CouponTarget::query()
            ->where('coupon_id', $coupon->id)
            ->whereNotNull('billable_metric_id')
            ->select('billable_metric_id');

        $plansFromBillableMetricLimitations = Charge::query()
            ->whereIn('billable_metric_id', $couponBillableMetricTargets)
            ->select('plan_id');

        $billableMetricsFromPlanLimitations = Charge::query()
            ->whereIn('plan_id', $couponPlanTargets)
            ->select('billable_metric_id');

        return DB::table('applied_coupons')
            ->join('coupons', 'coupons.id', '=', 'applied_coupons.coupon_id')
            ->join('coupon_targets', 'coupon_targets.coupon_id', '=', 'coupons.id')
            ->where('applied_coupons.customer_id', $this->customer->id)
            ->where('applied_coupons.status', AppliedCouponStatus::Active->value)
            ->whereNull('coupons.deleted_at')
            ->whereNull('coupon_targets.deleted_at')
            ->where(function ($query) use ($couponPlanTargets, $couponBillableMetricTargets, $plansFromBillableMetricLimitations, $billableMetricsFromPlanLimitations): void {
                $query->whereIn('coupon_targets.plan_id', $couponPlanTargets)
                    ->orWhereIn('coupon_targets.billable_metric_id', $couponBillableMetricTargets)
                    ->orWhereIn('coupon_targets.plan_id', $plansFromBillableMetricLimitations)
                    ->orWhereIn('coupon_targets.billable_metric_id', $billableMetricsFromPlanLimitations);
            })
            ->exists();
    }
}
