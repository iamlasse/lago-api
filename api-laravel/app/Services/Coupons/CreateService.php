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
 * Port of Rails' Coupons::CreateService
 * (app/services/coupons/create_service.rb).
 *
 * Not ported (dependencies do not exist yet):
 * - TODO(port): activity_loggable middleware (action: "coupon.created").
 */
class CreateService extends BaseService
{
    private array $limitations = [];

    private mixed $organizationId = null;

    public function __construct(
        private readonly array $args,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('coupon');
        $args = $this->args;

        if (! (new ValidateService($result, $args))->valid()) {
            return $result;
        }

        $this->limitations = is_array($args['applies_to'] ?? null) ? $args['applies_to'] : [];
        $this->organizationId = $args['organization_id'] ?? null;

        $planIdentifiers = $this->planIdentifiers();
        $billableMetricIdentifiers = $this->billableMetricIdentifiers();

        $coupon = new Coupon([
            'organization_id' => $this->organizationId,
            'name' => $args['name'] ?? null,
            'code' => $args['code'] ?? null,
            'description' => $args['description'] ?? null,
            'coupon_type' => $args['coupon_type'] ?? null,
            'amount_cents' => $args['amount_cents'] ?? null,
            'amount_currency' => $args['amount_currency'] ?? null,
            'percentage_rate' => self::numericArg($args['percentage_rate'] ?? null),
            'frequency' => $args['frequency'] ?? null,
            'frequency_duration' => $args['frequency_duration'] ?? null,
            'expiration' => $args['expiration'] ?? null,
            'expiration_at' => $args['expiration_at'] ?? null,
            'limited_plans' => $planIdentifiers !== [],
            'limited_billable_metrics' => $billableMetricIdentifiers !== [],
            'reusable' => array_key_exists('reusable', $args) ? $args['reusable'] : true,
        ]);

        $plans = $this->plans();

        if ($planIdentifiers !== [] && collect($planIdentifiers)->diff($plans->pluck($this->planKey()))->isNotEmpty()) {
            return $result->notFoundFailure('plans');
        }

        $billableMetrics = $this->billableMetrics();

        if ($billableMetricIdentifiers !== [] && $billableMetrics->count() !== count($billableMetricIdentifiers)) {
            return $result->notFoundFailure('billable_metrics');
        }

        if ($billableMetrics->isNotEmpty() && $plans->isNotEmpty()) {
            return $result->notAllowedFailure('only_one_limitation_type_per_coupon_allowed');
        }

        try {
            DB::transaction(function () use ($coupon, $result, $plans, $billableMetrics, $planIdentifiers, $billableMetricIdentifiers): void {
                $errors = $coupon->validateAttributes();

                if ($errors !== []) {
                    $result->recordValidationFailure($errors)->raiseIfError();
                }

                $coupon->save();

                if ($planIdentifiers !== []) {
                    foreach ($plans as $plan) {
                        CouponTarget::query()->create([
                            'coupon_id' => $coupon->id,
                            'plan_id' => $plan->id,
                            'organization_id' => $this->organizationId,
                        ]);
                    }
                }

                if ($billableMetricIdentifiers !== []) {
                    foreach ($billableMetrics as $billableMetric) {
                        CouponTarget::query()->create([
                            'coupon_id' => $coupon->id,
                            'billable_metric_id' => $billableMetric->id,
                            'organization_id' => $this->organizationId,
                        ]);
                    }
                }
            });

            // TODO(port): SendWebhookJob "coupon.created" — webhook emission
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

    /** Rails: `plan_identifiers` — nil becomes an empty list. */
    private function planIdentifiers(): array
    {
        $key = $this->apiContext() ? 'plan_codes' : 'plan_ids';
        $identifiers = $this->limitations[$key] ?? [];

        return array_values(array_unique(array_filter(
            is_array($identifiers) ? $identifiers : [],
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

        if ($identifiers === []) {
            return collect();
        }

        return Plan::query()
            ->whereIn($this->planKey(), $identifiers)
            ->where('organization_id', $this->organizationId)
            ->get();
    }

    private function billableMetricIdentifiers(): array
    {
        $key = $this->apiContext() ? 'billable_metric_codes' : 'billable_metric_ids';
        $identifiers = $this->limitations[$key] ?? [];

        return array_values(array_unique(array_filter(
            is_array($identifiers) ? $identifiers : [],
            static fn (mixed $identifier): bool => $identifier !== null,
        )));
    }

    /** @return Collection<int, BillableMetric> */
    private function billableMetrics(): Collection
    {
        $identifiers = $this->billableMetricIdentifiers();

        if ($identifiers === []) {
            return collect();
        }

        return BillableMetric::query()
            ->whereIn($this->apiContext() ? 'code' : 'id', $identifiers)
            ->where('organization_id', $this->organizationId)
            ->get();
    }
}
