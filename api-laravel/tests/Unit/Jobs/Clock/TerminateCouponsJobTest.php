<?php

declare(strict_types=1);

use App\Models\Coupon;
use Database\Factories\CouponFactory;
use App\Jobs\Clock\TerminateCouponsJob;

uses()->group('ledger:job:Clock.TerminateCouponsJob');

/**
 * Port of Rails' spec/jobs/clock/terminate_coupons_job_spec.rb — the hourly
 * sweep calls Coupons::TerminateService.terminate_all_expired.
 */
function clockCoupon(string $expirationAt): Coupon
{
    /** @var CouponFactory $factory */
    $factory = Coupon::factory();

    return $factory->timeLimit($expirationAt)->create();
}

it('terminates the expired coupons', function (): void {
    // Rails: TerminateService.terminate_all_expired terminates time-limited
    // coupons whose expiration_at has passed.
    $expired = clockCoupon(now()->subDay()->toDateTimeString());
    $kept = clockCoupon(now()->addYear()->toDateTimeString());

    (new TerminateCouponsJob)->handle();

    expect($expired->refresh()->statusEnum()?->label())->toBe('terminated')
        ->and($kept->refresh()->isTerminated())->toBeFalse();
});
