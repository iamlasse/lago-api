<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Models\Plan;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\BillableMetric;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\Failures\FailedResult;

/**
 * Port of Rails' Coupons::UpdateService
 * (app/services/coupons/update_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity_loggable middleware (action: "coupon.updated").
 */
class UpdateService extends BaseService
{
    private array $limitations = [];

    public function __construct(
        private readonly ?Coupon $coupon,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('coupon');
        $coupon = $this->coupon;

        if ($coupon === null) {
            return $result->notFoundFailure('coupon');
        }

        if (! (new ValidateService($result, $this->params))->valid()) {
            return $result;
        }

        $params = $this->params;

        if (array_key_exists('name', $params)) {
            $coupon->name = $params['name'];
        }

        if (array_key_exists('description', $params)) {
            $coupon->description = $params['description'];
        }

        if (array_key_exists('expiration', $params)) {
            $coupon->expiration = $params['expiration'];
        }

        if (array_key_exists('expiration_at', $params)) {
            $coupon->expiration_at = $params['expiration_at'];
        }

        $this->limitations = is_array($params['applies_to'] ?? null) ? $params['applies_to'] : [];
        $couponAlreadyApplied = $coupon->appliedCoupons()->exists();

        if (! $couponAlreadyApplied) {
            $planIdentifiers = $this->planIdentifiers();
            $billableMetricIdentifiers = $this->billableMetricIdentifiers();
            $plans = $this->plans();
            $billableMetrics = $this->billableMetrics();

            if ($planIdentifiers !== null && $plans->count() !== count($planIdentifiers)) {
                return $result->notFoundFailure('plans');
            }

            if ($billableMetricIdentifiers !== null && $billableMetrics->count() !== count($billableMetricIdentifiers)) {
                return $result->notFoundFailure('billable_metrics');
            }

            if ($billableMetrics->isNotEmpty() && $plans->isNotEmpty()) {
                return $result->notAllowedFailure('only_one_limitation_type_per_coupon_allowed');
            }

            if ($coupon->billableMetrics()->exists() && $plans->isNotEmpty() && $billableMetrics->isEmpty()) {
                $coupon->limited_billable_metrics = false;
            } elseif ($billableMetricIdentifiers !== null) {
                $coupon->limited_billable_metrics = $billableMetricIdentifiers !== [];
            }

            if ($coupon->plans()->exists() && $billableMetrics->isNotEmpty() && $plans->isEmpty()) {
                $coupon->limited_plans = false;
            } elseif ($planIdentifiers !== null) {
                $coupon->limited_plans = $planIdentifiers !== [];
            }

            if (array_key_exists('code', $params)) {
                $coupon->code = $params['code'];
            }

            if (array_key_exists('coupon_type', $params)) {
                $coupon->coupon_type = $params['coupon_type'];
            }

            if (array_key_exists('amount_cents', $params)) {
                $coupon->amount_cents = $params['amount_cents'];
            }

            if (array_key_exists('amount_currency', $params)) {
                $coupon->amount_currency = $params['amount_currency'];
            }

            if (array_key_exists('percentage_rate', $params)) {
                $coupon->percentage_rate = self::numericArg($params['percentage_rate']);
            }

            if (array_key_exists('frequency', $params)) {
                $coupon->frequency = $params['frequency'];
            }

            if (array_key_exists('frequency_duration', $params)) {
                $coupon->frequency_duration = $params['frequency_duration'];
            }

            if (array_key_exists('reusable', $params)) {
                $coupon->reusable = $params['reusable'];
            }
        }

        try {
            DB::transaction(function () use ($coupon, $result, $couponAlreadyApplied): void {
                $errors = $coupon->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $coupon->save();

                if (! $couponAlreadyApplied) {
                    $this->processPlans();
                    $this->processBillableMetrics();
                }
            });

            // TODO(port): SendWebhookJob "coupon.updated" — webhook emission
            // hook point.

            $result->coupon = $coupon;

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

    /**
     * Rails: `plan_identifiers` — nil while the key is absent (the "not
     * provided" case), a compacted, unique list otherwise.
     */
    private function planIdentifiers(): ?array
    {
        $key = $this->apiContext() ? 'plan_codes' : 'plan_ids';

        if (! array_key_exists($key, $this->limitations) || ! is_array($this->limitations[$key])) {
            return null;
        }

        return array_values(array_unique(array_filter(
            $this->limitations[$key],
            static fn (mixed $identifier): bool => $identifier !== null,
        )));
    }

    private function planKey(): string
    {
        return $this->apiContext() ? 'code' : 'id';
    }

    /** @return Collection<int, Plan> */
    private function plans(): Collection
    {
        $identifiers = $this->planIdentifiers();

        if ($identifiers === null || $identifiers === []) {
            return collect();
        }

        return Plan::query()
            ->whereIn($this->planKey(), $identifiers)
            ->where('organization_id', $this->coupon->organization_id)
            ->get();
    }

    private function billableMetricIdentifiers(): ?array
    {
        $key = $this->apiContext() ? 'billable_metric_codes' : 'billable_metric_ids';

        if (! array_key_exists($key, $this->limitations) || ! is_array($this->limitations[$key])) {
            return null;
        }

        return array_values(array_unique(array_filter(
            $this->limitations[$key],
            static fn (mixed $identifier): bool => $identifier !== null,
        )));
    }

    /** @return Collection<int, BillableMetric> */
    private function billableMetrics(): Collection
    {
        $identifiers = $this->billableMetricIdentifiers();

        if ($identifiers === null || $identifiers === []) {
            return collect();
        }

        return BillableMetric::query()
            ->whereIn($this->apiContext() ? 'code' : 'id', $identifiers)
            ->where('organization_id', $this->coupon->organization_id)
            ->get();
    }

    private function processPlans(): void
    {
        $coupon = $this->coupon;
        $existingCouponPlanIds = $coupon->couponTargets()->whereNotNull('plan_id')->pluck('plan_id')->all();

        foreach ($this->plans() as $plan) {
            if (in_array((string) $plan->id, array_map('strval', $existingCouponPlanIds), true)) {
                continue;
            }

            CouponTarget::query()->create([
                'coupon_id' => $coupon->id,
                'plan_id' => $plan->id,
                'organization_id' => $coupon->organization_id,
            ]);
        }

        $this->sanitizeCouponPlans();
    }

    private function sanitizeCouponPlans(): void
    {
        $planIds = $this->plans()->pluck('id')->all();
        $existingCouponPlanIds = $this->coupon->couponTargets()->whereNotNull('plan_id')->pluck('plan_id')->all();

        $notNeededCouponPlanIds = array_values(array_diff(
            array_map('strval', $existingCouponPlanIds),
            array_map('strval', $planIds),
        ));

        foreach ($notNeededCouponPlanIds as $couponPlanId) {
            $this->coupon->couponTargets()
                ->where('plan_id', $couponPlanId)
                ->first()
                ?->delete();
        }
    }

    private function processBillableMetrics(): void
    {
        $coupon = $this->coupon;
        $existingCouponBillableMetricIds = $coupon->couponTargets()
            ->whereNotNull('billable_metric_id')
            ->pluck('billable_metric_id')
            ->all();

        foreach ($this->billableMetrics() as $billableMetric) {
            if (in_array((string) $billableMetric->id, array_map('strval', $existingCouponBillableMetricIds), true)) {
                continue;
            }

            CouponTarget::query()->create([
                'coupon_id' => $coupon->id,
                'billable_metric_id' => $billableMetric->id,
                'organization_id' => $coupon->organization_id,
            ]);
        }

        $this->sanitizeCouponBillableMetrics();
    }

    private function sanitizeCouponBillableMetrics(): void
    {
        $billableMetricIds = $this->billableMetrics()->pluck('id')->all();
        $existingCouponBillableMetricIds = $this->coupon->couponTargets()
            ->whereNotNull('billable_metric_id')
            ->pluck('billable_metric_id')
            ->all();

        $notNeededCouponBillableMetricIds = array_values(array_diff(
            array_map('strval', $existingCouponBillableMetricIds),
            array_map('strval', $billableMetricIds),
        ));

        foreach ($notNeededCouponBillableMetricIds as $couponBillableMetricId) {
            $this->coupon->couponTargets()
                ->where('billable_metric_id', $couponBillableMetricId)
                ->first()
                ?->delete();
        }
    }
}
