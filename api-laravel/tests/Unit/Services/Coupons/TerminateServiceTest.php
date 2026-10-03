<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Enums\CouponStatus;
use App\Models\Organization;
use App\Services\Coupons\TerminateService;
use App\Services\Failures\NotFoundFailure;

it('terminates an active coupon', function (): void {
    $organization = Organization::factory()->create();
    $coupon = Coupon::factory()->for($organization)->create();

    $result = TerminateService::call($coupon);

    expect($result->success())->toBeTrue()
        ->and($coupon->fresh()->isTerminated())->toBeTrue()
        ->and($coupon->fresh()->terminated_at)->not->toBeNull();
})->group('ledger:svc:Coupons.TerminateService');

it('is idempotent when the coupon is already terminated', function (): void {
    $organization = Organization::factory()->create();
    $terminatedAt = now('UTC')->subDay();
    $coupon = Coupon::factory()->for($organization)->create([
        'status' => CouponStatus::Terminated,
        'terminated_at' => $terminatedAt,
    ]);

    $result = TerminateService::call($coupon);

    expect($result->success())->toBeTrue()
        ->and($coupon->fresh()->terminated_at->equalTo($terminatedAt))->toBeTrue();
});

it('fails when the coupon does not exist', function (): void {
    $result = TerminateService::call(null);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('coupon_not_found');
});

it('terminates all expired time-limited coupons', function (): void {
    $organization = Organization::factory()->create();

    $expired = Coupon::factory()->for($organization)->timeLimit(now('UTC')->subDay()->toDateTimeString())->create();
    $active = Coupon::factory()->for($organization)->timeLimit(now('UTC')->addDay()->toDateTimeString())->create();
    $noExpiration = Coupon::factory()->for($organization)->create();

    TerminateService::terminateAllExpired();

    expect($expired->fresh()->isTerminated())->toBeTrue()
        ->and($active->fresh()->isTerminated())->toBeFalse()
        ->and($noExpiration->fresh()->isTerminated())->toBeFalse();
});
