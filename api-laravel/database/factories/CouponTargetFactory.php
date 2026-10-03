<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\BillableMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Port of Rails' :coupon_plan / :coupon_billable_metric factories
 * (spec/factories/coupon_targets.rb, class CouponTarget). The catalog-plan
 * variant (:coupon_catalog_plan) has no equivalent — catalog plans are not
 * part of the legacy-engine port.
 *
 * @extends Factory<CouponTarget>
 */
class CouponTargetFactory extends Factory
{
    protected $model = CouponTarget::class;

    public function definition(): array
    {
        return [
            'coupon_id' => CouponFactory::new(),
            'plan_id' => PlanFactory::new(),
            // Rails: organization { plan&.organization || coupon&.organization }.
            'organization_id' => function (array $attributes): string {
                $plan = $this->resolve($attributes['plan_id'] ?? null, Plan::class);

                if ($plan !== null) {
                    return (string) $plan->organization_id;
                }

                $coupon = $this->resolve($attributes['coupon_id'] ?? null, Coupon::class);

                return (string) ($coupon?->organization_id ?? Plan::factory()->create()->organization_id);
            },
        ];
    }

    /** Rails factory :coupon_plan — a plan-limited target. */
    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => [
            'plan_id' => $plan->id,
            'organization_id' => $plan->organization_id,
        ]);
    }

    /** Rails factory :coupon_billable_metric — a billable-metric-limited target. */
    public function forBillableMetric(BillableMetric $billableMetric): static
    {
        return $this->state(fn () => [
            'plan_id' => null,
            'billable_metric_id' => $billableMetric->id,
            'organization_id' => $billableMetric->organization_id,
        ]);
    }

    private function resolve(mixed $value, string $class): ?object
    {
        if ($value instanceof $class) {
            return $value;
        }

        if (is_string($value)) {
            return $class::query()->find($value);
        }

        return null;
    }
}
