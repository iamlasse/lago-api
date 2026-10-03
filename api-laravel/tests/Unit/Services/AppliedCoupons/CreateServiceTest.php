<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Charge;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CouponTarget;
use App\Models\Organization;
use App\Models\AppliedCoupon;
use App\Models\BillableMetric;
use App\Services\Failures\NotFoundFailure;
use App\Services\Failures\ValidationFailure;
use App\Services\AppliedCoupons\CreateService;
use App\Services\Failures\MethodNotAllowedFailure;

function appliedCouponCustomer(): array
{
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create(['currency' => null]);
    $coupon = Coupon::factory()->for($organization)->create(['code' => 'SUMMER', 'amount_cents' => 2000]);

    return [$organization, $customer, $coupon];
}

it('applies a coupon to a customer with the coupon defaults', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();

    $result = CreateService::call(customer: $customer, coupon: $coupon, params: []);

    expect($result->success())->toBeTrue()
        ->and($result->applied_coupon->coupon_id)->toBe($coupon->id)
        ->and($result->applied_coupon->customer_id)->toBe($customer->id)
        ->and($result->applied_coupon->amount_cents)->toBe(2000)
        ->and($result->applied_coupon->amount_currency)->toBe('EUR')
        ->and($result->applied_coupon->frequencyEnum()?->label())->toBe('once')
        ->and($result->applied_coupon->frequency_duration)->toBe(1)
        ->and($result->applied_coupon->frequency_duration_remaining)->toBe(1)
        ->and($result->applied_coupon->isActive())->toBeTrue();
})->group('ledger:svc:AppliedCoupons.CreateService');

it('overrides the coupon defaults with the given params', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();

    $result = CreateService::call(customer: $customer, coupon: $coupon, params: [
        'amount_cents' => 500,
        'amount_currency' => 'USD',
    ]);

    expect($result->success())->toBeTrue()
        ->and($result->applied_coupon->amount_cents)->toBe(500)
        ->and($result->applied_coupon->amount_currency)->toBe('USD')
        ->and($customer->fresh()->currency)->toBe('USD');
});

it('applies a recurring coupon with the duration remaining', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();
    $coupon->update(['frequency' => 'recurring', 'frequency_duration' => 3]);

    $result = CreateService::call(customer: $customer, coupon: $coupon, params: []);

    expect($result->success())->toBeTrue()
        ->and($result->applied_coupon->recurring())->toBeTrue()
        ->and($result->applied_coupon->frequency_duration)->toBe(3)
        ->and($result->applied_coupon->frequency_duration_remaining)->toBe(3);
});

it('fails when the customer does not exist', function (): void {
    $result = CreateService::call(customer: null, coupon: null, params: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('customer_not_found');
});

it('fails when the coupon does not exist', function (): void {
    [, $customer] = appliedCouponCustomer();

    $result = CreateService::call(customer: $customer, coupon: null, params: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('coupon_not_found');
});

it('fails when the coupon is terminated', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();
    $coupon->markAsTerminated();

    $result = CreateService::call(customer: $customer, coupon: $coupon, params: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError()->getMessage())->toBe('coupon_not_found');
});

it('fails when a non-reusable coupon is applied twice', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();

    $first = CreateService::call(customer: $customer, coupon: $coupon, params: []);
    $second = CreateService::call(customer: $customer, coupon: $coupon, params: []);

    expect($first->success())->toBeTrue()
        ->and($second->failure())->toBeTrue()
        ->and($second->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($second->getError()->messages['coupon'])->toBe(['coupon_is_not_reusable']);
});

it('applies a non-reusable coupon twice to different customers', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();
    $otherCustomer = Customer::factory()->for($coupon->organization)->create();

    $first = CreateService::call(customer: $customer, coupon: $coupon, params: []);
    $second = CreateService::call(customer: $otherCustomer, coupon: $coupon, params: []);

    expect($first->success())->toBeTrue()
        ->and($second->success())->toBeTrue();
});

it('fails when a reusable=false coupon is applied twice even when reusable flag is true later', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();

    AppliedCoupon::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
    ]);
    $coupon->update(['reusable' => true]);

    $result = CreateService::call(customer: $customer, coupon: $coupon, params: []);

    expect($result->success())->toBeTrue();
});

it('fails when a plan-limited coupon overlaps an applied one', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();
    $plan = Plan::factory()->for($coupon->organization)->create();
    $otherCoupon = Coupon::factory()->for($coupon->organization)->limitedPlans()->create();
    $coupon->update(['limited_plans' => true]);

    CouponTarget::factory()->for($coupon, 'coupon')->forPlan($plan)->create();
    CouponTarget::factory()->for($otherCoupon, 'coupon')->forPlan($plan)->create();

    AppliedCoupon::factory()->create([
        'coupon_id' => $otherCoupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $customer->organization_id,
    ]);

    $result = CreateService::call(customer: $customer, coupon: $coupon, params: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($result->getError()->code)->toBe('plan_overlapping');
});

it('fails when a billable-metric-limited coupon overlaps through plan charges', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();
    $organization = $coupon->organization;
    $plan = Plan::factory()->for($organization)->create();
    $billableMetric = BillableMetric::factory()->for($organization)->create();
    Charge::factory()->for($plan, 'plan')->for($billableMetric, 'billableMetric')->create();

    $planLimited = Coupon::factory()->for($organization)->limitedPlans()->create();
    $billableMetricLimited = Coupon::factory()->for($organization)->limitedBillableMetrics()->create();

    CouponTarget::factory()->for($planLimited, 'coupon')->forPlan($plan)->create();
    CouponTarget::factory()->for($billableMetricLimited, 'coupon')->forBillableMetric($billableMetric)->create();

    AppliedCoupon::factory()->create([
        'coupon_id' => $planLimited->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $result = CreateService::call(customer: $customer, coupon: $billableMetricLimited, params: []);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(MethodNotAllowedFailure::class)
        ->and($result->getError()->code)->toBe('plan_overlapping');
});

it('applies a limited coupon when targets do not overlap', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();
    $organization = $coupon->organization;

    $planA = Plan::factory()->for($organization)->create();
    $planB = Plan::factory()->for($organization)->create();

    $limitedA = Coupon::factory()->for($organization)->limitedPlans()->create();
    $limitedB = Coupon::factory()->for($organization)->limitedPlans()->create();

    CouponTarget::factory()->for($limitedA, 'coupon')->forPlan($planA)->create();
    CouponTarget::factory()->for($limitedB, 'coupon')->forPlan($planB)->create();

    AppliedCoupon::factory()->create([
        'coupon_id' => $limitedA->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $result = CreateService::call(customer: $customer, coupon: $limitedB, params: []);

    expect($result->success())->toBeTrue();
});

it('fails with a validation error when the applied coupon is invalid', function (): void {
    [, $customer, $coupon] = appliedCouponCustomer();
    $coupon->update(['frequency' => 'recurring', 'frequency_duration' => 3]);

    $result = CreateService::call(customer: $customer, coupon: $coupon, params: [
        'frequency' => 'recurring',
        'frequency_duration' => 0,
    ]);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages['frequency_duration'])->toBe(['value_is_out_of_range']);
});
