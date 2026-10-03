<?php

declare(strict_types=1);

use App\Models\AppliedCoupon;
use App\Services\Failures\NotFoundFailure;
use App\Services\AppliedCoupons\TerminateService;

it('terminates an active applied coupon', function (): void {
    $appliedCoupon = AppliedCoupon::factory()->create();

    $result = TerminateService::call(appliedCoupon: $appliedCoupon);

    expect($result->success())->toBeTrue()
        ->and($appliedCoupon->fresh()->isTerminated())->toBeTrue()
        ->and($appliedCoupon->fresh()->terminated_at)->not->toBeNull();
})->group('ledger:svc:AppliedCoupons.TerminateService');

it('is idempotent when the applied coupon is already terminated', function (): void {
    $terminatedAt = now('UTC')->subDay();
    $appliedCoupon = AppliedCoupon::factory()->terminated()->create([
        'terminated_at' => $terminatedAt,
    ]);

    $result = TerminateService::call(appliedCoupon: $appliedCoupon);

    expect($result->success())->toBeTrue()
        ->and($appliedCoupon->fresh()->terminated_at->equalTo($terminatedAt))->toBeTrue();
});

it('fails when the applied coupon does not exist', function (): void {
    $result = TerminateService::call(appliedCoupon: null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('applied_coupon_not_found');
});
