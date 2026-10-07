<?php

declare(strict_types=1);

use App\Models\Coupon;
use Illuminate\Support\Facades\Queue;
use App\Jobs\Clock\TerminateCouponsJob;
use Database\Factories\CouponFactory;

uses()->group('ledger:job:Clock.TerminateCouponsJob');

/**
 * Port of Rails' spec/jobs/clock/terminate_coupons_job_spec.rb — the hourly
 * sweep calls Coupons::TerminateService.terminate_all_expired.
 */
it('terminates the expired coupons', function (): void {
    Queue::fake();

    // Rails: TerminateService.terminate_all_expired terminates time-limited
    // coupons whose expiration_at has passed.
    $expired = Coupon::factory()->create([
        'expiration' => 1, // time_limit
        'expiration_at' => now()->subDay(),
    ]);

    $kept = Coupon::factory()->create([
        'expiration' => 1, // time_limit
        'expiration_at' => now()->addYear(),
    ]);

    (new TerminateCouponsJob)->handle();

    expect($expired->refresh()->status)->toBe(1) // terminated
        ->and($kept->refresh()->status)->toBe(0); // active
});
