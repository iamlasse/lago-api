<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CouponTarget;
use App\Models\Organization;
use App\Models\AppliedCoupon;
use App\Services\Coupons\DestroyService;
use App\Services\Failures\NotFoundFailure;

it('discards the coupon and terminates the applied coupons', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->create();

    $target = CouponTarget::factory()->for($coupon, 'coupon')->create();
    $activeApplied = AppliedCoupon::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);
    $terminatedApplied = AppliedCoupon::factory()->terminated()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $result = DestroyService::call(coupon: $coupon);

    expect($result->success())->toBeTrue()
        ->and($coupon->fresh()->trashed())->toBeTrue()
        ->and($target->fresh()->trashed())->toBeTrue()
        ->and($activeApplied->fresh()->isTerminated())->toBeTrue()
        ->and($terminatedApplied->fresh()->isTerminated())->toBeTrue()
        ->and(Coupon::query()->count())->toBe(0)
        ->and(Coupon::withTrashed()->count())->toBe(1);
})->group('ledger:svc:Coupons.DestroyService');

it('fails when the coupon does not exist', function (): void {
    $result = DestroyService::call(coupon: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('coupon_not_found');
});
