<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\CouponTarget;
use App\Models\Organization;
use App\Models\BillableMetric;
use App\Serializers\V1\CouponSerializer;

/**
 * Port of spec/serializers/v1/coupon_serializer_spec.rb.
 */
it('serializes the object', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->for($organization)->create();
    $coupon = App\Models\Coupon::factory()->for($organization)->create([
        'name' => 'Summer promo',
        'code' => 'SUMMER',
        'description' => 'Summer discount',
        'limited_plans' => true,
    ]);
    CouponTarget::factory()->for($coupon, 'coupon')->forPlan($plan)->create();

    $payload = (new CouponSerializer($coupon))->serialize();

    expect($payload['lago_id'])->toBe($coupon->id)
        ->and($payload['name'])->toBe('Summer promo')
        ->and($payload['code'])->toBe('SUMMER')
        ->and($payload['description'])->toBe('Summer discount')
        ->and($payload['coupon_type'])->toBe('fixed_amount')
        ->and($payload['amount_cents'])->toBe(1000)
        ->and($payload['amount_currency'])->toBe('EUR')
        ->and($payload['percentage_rate'])->toBeNull()
        ->and($payload['frequency'])->toBe('once')
        ->and($payload['frequency_duration'])->toBe(1)
        ->and($payload['reusable'])->toBeFalse()
        ->and($payload['limited_plans'])->toBeTrue()
        ->and($payload['limited_billable_metrics'])->toBeFalse()
        ->and($payload['plan_codes'])->toBe([$plan->code])
        ->and($payload['billable_metric_codes'])->toBe([])
        ->and($payload['expiration'])->toBe('no_expiration')
        ->and($payload['expiration_at'])->toBeNull()
        ->and($payload['terminated_at'])->toBeNull()
        ->and($payload['created_at'])->toBe($coupon->created_at->utc()->format('Y-m-d\TH:i:s\Z'));
})->group('ledger:ser:V1.CouponSerializer');

it('serializes a percentage coupon rate as a fixed-notation decimal', function (): void {
    $organization = Organization::factory()->create();
    $coupon = App\Models\Coupon::factory()->for($organization)->percentage('20.5')->create();

    $payload = (new CouponSerializer($coupon))->serialize();

    expect($payload['percentage_rate'])->toBe('20.5');
});

it('only lists parent plans', function (): void {
    $organization = Organization::factory()->create();
    $plan = Plan::factory()->for($organization)->create();
    $childPlan = Plan::factory()->for($organization)->create(['parent_id' => $plan->id]);
    $coupon = App\Models\Coupon::factory()->for($organization)->create();
    CouponTarget::factory()->for($coupon, 'coupon')->forPlan($plan)->create();
    CouponTarget::factory()->for($coupon, 'coupon')->forPlan($childPlan)->create();

    $payload = (new CouponSerializer($coupon))->serialize();

    expect($payload['plan_codes'])->toBe([$plan->code]);
});

it('serializes the billable metric codes', function (): void {
    $organization = Organization::factory()->create();
    $billableMetric = BillableMetric::factory()->for($organization)->create();
    $coupon = App\Models\Coupon::factory()->for($organization)->limitedBillableMetrics()->create();
    CouponTarget::factory()->for($coupon, 'coupon')->forBillableMetric($billableMetric)->create();

    $payload = (new CouponSerializer($coupon))->serialize();

    expect($payload['billable_metric_codes'])->toBe([$billableMetric->code])
        ->and($payload['plan_codes'])->toBe([]);
});

it('serializes the time limit expiration', function (): void {
    $organization = Organization::factory()->create();
    $expirationAt = now('UTC')->addDays(3)->startOfSecond();
    $coupon = App\Models\Coupon::factory()->for($organization)->timeLimit($expirationAt->format('Y-m-d H:i:s'))->create();

    $payload = (new CouponSerializer($coupon))->serialize();

    expect($payload['expiration'])->toBe('time_limit')
        ->and($payload['expiration_at'])->toBe($expirationAt->format('Y-m-d\TH:i:s\Z'));
});
