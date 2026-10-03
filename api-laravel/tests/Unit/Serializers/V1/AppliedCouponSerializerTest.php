<?php

declare(strict_types=1);

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\AppliedCoupon;
use App\Serializers\V1\AppliedCouponSerializer;

/**
 * Port of spec/serializers/v1/applied_coupon_serializer_spec.rb.
 */
it('serializes the object with the credits include', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create(['external_id' => 'ext_123']);
    $coupon = App\Models\Coupon::factory()->for($organization)->create([
        'name' => 'Summer promo',
        'code' => 'SUMMER',
        'description' => 'Summer discount',
    ]);
    $appliedCoupon = AppliedCoupon::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'amount_cents' => 1000,
        'amount_currency' => 'EUR',
    ]);
    $invoice = Invoice::factory()->for($organization)->for($customer, 'customer')->create([
        'currency' => 'EUR',
    ]);
    $credit = Credit::factory()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'applied_coupon_id' => $appliedCoupon->id,
        'amount_cents' => 50,
        'amount_currency' => 'EUR',
        'before_taxes' => true,
    ]);

    $payload = (new AppliedCouponSerializer($appliedCoupon, ['includes' => ['credits']]))->serialize();

    expect($payload['lago_id'])->toBe($appliedCoupon->id)
        ->and($payload['lago_coupon_id'])->toBe($coupon->id)
        ->and($payload['coupon_code'])->toBe('SUMMER')
        ->and($payload['coupon_name'])->toBe('Summer promo')
        ->and($payload['coupon_description'])->toBe('Summer discount')
        ->and($payload['coupon_status'])->toBe('active')
        ->and($payload['coupon_deleted_at'])->toBeNull()
        ->and($payload['lago_customer_id'])->toBe($customer->id)
        ->and($payload['external_customer_id'])->toBe('ext_123')
        ->and($payload['status'])->toBe('active')
        ->and($payload['amount_cents'])->toBe(1000)
        ->and($payload['amount_cents_remaining'])->toBe(950)
        ->and($payload['amount_currency'])->toBe('EUR')
        ->and($payload['percentage_rate'])->toBeNull()
        ->and($payload['frequency'])->toBe('once')
        ->and($payload['frequency_duration'])->toBe(1)
        ->and($payload['frequency_duration_remaining'])->toBe(1)
        ->and($payload['expiration_at'])->toBeNull()
        ->and($payload['terminated_at'])->toBeNull()
        ->and($payload['credits'])->toHaveCount(1)
        ->and($payload['credits'][0]['lago_id'])->toBe($credit->id)
        ->and($payload['credits'][0]['amount_cents'])->toBe(50)
        ->and($payload['credits'][0]['item']['type'])->toBe('coupon')
        ->and($payload['credits'][0]['item']['code'])->toBe('SUMMER')
        ->and($payload['credits'][0]['invoice']['lago_id'])->toBe($invoice->id)
        ->and($payload['credits'][0]['invoice']['payment_status'])->toBe($invoice->paymentStatusEnum()?->label());
})->group('ledger:ser:V1.AppliedCouponSerializer');

it('does not include credits when the include is absent', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = App\Models\Coupon::factory()->for($organization)->create();
    $appliedCoupon = AppliedCoupon::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $payload = (new AppliedCouponSerializer($appliedCoupon))->serialize();

    expect($payload)->not->toHaveKey('credits');
});

it('omits the remaining amount for recurring and forever frequencies', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = App\Models\Coupon::factory()->for($organization)->create();
    $recurring = AppliedCoupon::factory()->recurring(3)->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);
    $forever = AppliedCoupon::factory()->forever()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $recurringPayload = (new AppliedCouponSerializer($recurring))->serialize();
    $foreverPayload = (new AppliedCouponSerializer($forever))->serialize();

    expect($recurringPayload['amount_cents_remaining'])->toBeNull()
        ->and($foreverPayload['amount_cents_remaining'])->toBeNull();
});

it('omits the remaining amount for percentage coupons', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = App\Models\Coupon::factory()->for($organization)->percentage('20')->create();
    $appliedCoupon = AppliedCoupon::factory()->percentage('20')->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $payload = (new AppliedCouponSerializer($appliedCoupon))->serialize();

    expect($payload['amount_cents_remaining'])->toBeNull()
        ->and($payload['percentage_rate'])->toBe('20.0');
});

it('serializes the coupon deleted at when the coupon was discarded', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = App\Models\Coupon::factory()->for($organization)->deleted()->create();
    $appliedCoupon = AppliedCoupon::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);

    $payload = (new AppliedCouponSerializer($appliedCoupon))->serialize();

    expect($payload['coupon_deleted_at'])->toBe($coupon->deleted_at->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($payload['coupon_status'])->toBe('active');
});
