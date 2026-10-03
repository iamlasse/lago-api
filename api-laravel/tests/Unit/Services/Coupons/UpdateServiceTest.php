<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Coupon;
use App\Models\CouponTarget;
use App\Models\Organization;
use App\Models\BillableMetric;
use App\Support\CurrentContext;
use App\Services\Coupons\UpdateService;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\Failures\MethodNotAllowedFailure;

beforeEach(function (): void {
    CurrentContext::reset();
    CurrentContext::$source = 'api';
});

it('updates the mutable attributes of a coupon', function (): void {
    $organization = Organization::factory()->create();
    $coupon = Coupon::factory()->for($organization)->create([
        'code' => 'SUMMER',
        'name' => 'Summer promo',
        'description' => 'Summer discount',
    ]);

    $result = UpdateService::call(
        coupon: $coupon,
        params: [
            'name' => 'New name',
            'description' => 'New description',
            'expiration' => 'time_limit',
            'expiration_at' => now()->addYear()->toISOString(),
        ],
    );

    expect($result->success())->toBeTrue()
        ->and($result->coupon->name)->toBe('New name')
        ->and($result->coupon->description)->toBe('New description')
        ->and($result->coupon->expirationEnum()?->label())->toBe('time_limit')
        ->and($result->coupon->expiration_at)->not->toBeNull();
})->group('ledger:svc:Coupons.UpdateService');

it('fails when the coupon does not exist', function (): void {
    $result = UpdateService::call(coupon: null, params: ['name' => 'New name']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('coupon_not_found');
});

it('fails with a validation error', function (): void {
    $organization = Organization::factory()->create();
    $coupon = Coupon::factory()->for($organization)->create();

    $result = UpdateService::call(coupon: $coupon, params: ['name' => '']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['name'])->toBe(['value_is_mandatory']);
});

it('fails when the expiration date is invalid', function (): void {
    $organization = Organization::factory()->create();
    $coupon = Coupon::factory()->for($organization)->create();

    $result = UpdateService::call(coupon: $coupon, params: ['expiration_at' => '2022-01-01T00:00:00Z']);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->messages['expiration_at'])->toBe(['invalid_date']);
});

it('creates and removes plan coupon targets', function (): void {
    $organization = Organization::factory()->create();
    $planA = Plan::factory()->for($organization)->create();
    $planB = Plan::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->limitedPlans()->create(['code' => 'SUMMER']);

    CouponTarget::factory()->for($coupon, 'coupon')->forPlan($planA)->create();

    $result = UpdateService::call(
        coupon: $coupon,
        params: ['applies_to' => ['plan_codes' => [$planB->code]]],
    );

    expect($result->success())->toBeTrue()
        ->and($coupon->plans()->pluck('plans.code')->all())->toBe([$planB->code])
        ->and($coupon->fresh()->limited_plans)->toBeTrue()
        // Rails soft-destroys the unneeded target row.
        ->and($coupon->couponTargets()->withTrashed()->count())->toBe(2);
});

it('creates and removes billable metric coupon targets', function (): void {
    $organization = Organization::factory()->create();
    $billableMetricA = BillableMetric::factory()->for($organization)->create();
    $billableMetricB = BillableMetric::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->limitedBillableMetrics()->create(['code' => 'SUMMER']);

    CouponTarget::factory()->for($coupon, 'coupon')->forBillableMetric($billableMetricA)->create();

    $result = UpdateService::call(
        coupon: $coupon,
        params: ['applies_to' => ['billable_metric_codes' => [$billableMetricB->code]]],
    );

    expect($result->success())->toBeTrue()
        ->and($coupon->billableMetrics()->pluck('billable_metrics.code')->all())->toBe([$billableMetricB->code])
        ->and($coupon->fresh()->limited_billable_metrics)->toBeTrue();
});

it('clears the limitation flags when targets are emptied', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->limitedPlans()->create(['code' => 'SUMMER']);

    CouponTarget::factory()->for($coupon, 'coupon')->forPlan($plan)->create();

    $result = UpdateService::call(
        coupon: $coupon,
        params: ['applies_to' => ['plan_codes' => []]],
    );

    expect($result->success())->toBeTrue()
        ->and($coupon->fresh()->limited_plans)->toBeFalse()
        ->and($coupon->couponTargets()->count())->toBe(0);
});

it('fails when a plan code does not exist', function (): void {
    $organization = Organization::factory()->create();
    $coupon = Coupon::factory()->for($organization)->create();

    $result = UpdateService::call(
        coupon: $coupon,
        params: ['applies_to' => ['plan_codes' => ['unknown_code']]],
    );

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('plans_not_found');
});

it('does not allow both limitation types on one coupon', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->for($organization)->create();
    $billableMetric = BillableMetric::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->create();

    $result = UpdateService::call(coupon: $coupon, params: [
        'applies_to' => [
            'plan_codes' => [$plan->code],
            'billable_metric_codes' => [$billableMetric->code],
        ],
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($result->getError()->code)->toBe('only_one_limitation_type_per_coupon_allowed');
});

it('keeps the immutable attributes when the coupon was already applied', function (): void {
    $organization = Organization::factory()->create();
    $customer = App\Models\Customer::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->create([
        'code' => 'SUMMER',
        'name' => 'Summer promo',
        'amount_cents' => 2000,
    ]);
    App\Models\AppliedCoupon::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $result = UpdateService::call(coupon: $coupon, params: [
        'name' => 'New name',
        'code' => 'NEW_CODE',
        'amount_cents' => 9999,
        'coupon_type' => 'percentage',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->coupon->name)->toBe('New name')
        ->and($result->coupon->fresh()->code)->toBe('SUMMER')
        ->and($result->coupon->fresh()->amount_cents)->toBe(2000)
        ->and($result->coupon->fresh()->typeEnum()?->label())->toBe('fixed_amount');
});
