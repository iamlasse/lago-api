<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Models\AppliedCoupon;
use App\Services\Failures\NotFoundFailure;
use App\Services\AppliedCoupons\RecreditService;

function recreditCouponCredit(Organization $organization, Customer $customer, AppliedCoupon $appliedCoupon): Credit
{
    $invoice = Invoice::factory()->for($organization)->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Voided,
        'currency' => 'EUR',
    ]);

    return Credit::factory()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'applied_coupon_id' => $appliedCoupon->id,
        'amount_cents' => 200,
        'amount_currency' => 'EUR',
        'before_taxes' => true,
    ]);
}

it('increments the frequency duration remaining of a recurring coupon', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $appliedCoupon = AppliedCoupon::factory()->recurring(2)->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'frequency_duration_remaining' => 1,
    ]);
    $credit = recreditCouponCredit($organization, $customer, $appliedCoupon);

    $result = RecreditService::call(credit: $credit);

    expect($result->success())->toBeTrue()
        ->and($appliedCoupon->fresh()->frequency_duration_remaining)->toBe(2)
        ->and($appliedCoupon->fresh()->isTerminated())->toBeFalse();
})->group('ledger:svc:AppliedCoupons.RecreditService');

it('reactivates a terminated once coupon when the parent coupon is still active', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->create();
    $appliedCoupon = AppliedCoupon::factory()->terminated()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);
    $credit = recreditCouponCredit($organization, $customer, $appliedCoupon);

    $result = RecreditService::call(credit: $credit);

    expect($result->success())->toBeTrue()
        ->and($appliedCoupon->fresh()->isActive())->toBeTrue()
        ->and($appliedCoupon->fresh()->terminated_at)->toBeNull();
});

it('does not reactivate a terminated once coupon when the parent coupon is terminated', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->terminated()->create();
    $appliedCoupon = AppliedCoupon::factory()->terminated()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);
    $credit = recreditCouponCredit($organization, $customer, $appliedCoupon);

    $result = RecreditService::call(credit: $credit);

    expect($result->success())->toBeTrue()
        ->and($appliedCoupon->fresh()->isTerminated())->toBeTrue();
});

it('does not reactivate a terminated forever coupon', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $coupon = Coupon::factory()->for($organization)->forever()->create();
    $appliedCoupon = AppliedCoupon::factory()->forever()->terminated()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
    ]);
    $credit = recreditCouponCredit($organization, $customer, $appliedCoupon);

    $result = RecreditService::call(credit: $credit);

    expect($result->success())->toBeTrue()
        ->and($appliedCoupon->fresh()->isTerminated())->toBeTrue();
});

it('leaves an active once coupon untouched', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $appliedCoupon = AppliedCoupon::factory()->create([
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'frequency_duration_remaining' => 1,
    ]);
    $credit = recreditCouponCredit($organization, $customer, $appliedCoupon);

    $result = RecreditService::call(credit: $credit);

    expect($result->success())->toBeTrue()
        ->and($appliedCoupon->fresh()->isActive())->toBeTrue()
        ->and($appliedCoupon->fresh()->frequency_duration_remaining)->toBe(1);
});

it('fails when the credit has no applied coupon', function (): void {
    $organization = Organization::factory()->create();
    $customer = Customer::factory()->for($organization)->create();
    $invoice = Invoice::factory()->for($organization)->for($customer, 'customer')->create();

    $credit = Credit::factory()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'applied_coupon_id' => null,
        'amount_cents' => 200,
        'amount_currency' => 'EUR',
    ]);

    $result = RecreditService::call(credit: $credit);

    expect($result->failure())->toBeTrue()
        ->and($result->getError())->toBeInstanceOf(NotFoundFailure::class)
        ->and($result->getError()->getMessage())->toBe('applied_coupon_not_found');
});
