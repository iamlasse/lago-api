<?php

declare(strict_types=1);

use App\Models\Credit;
use App\Serializers\V1\CreditSerializer;

/**
 * Port of spec/serializers/v1/credit_serializer_spec.rb — a coupon credit
 * on an invoice (the credit-note / progressive-billing item variants map
 * the same item_* accessors to other sources).
 */
it('serializes an applied-coupon credit with literal snake_case keys', function (): void {
    $organization = App\Models\Organization::factory()->create();
    $customer = App\Models\Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = App\Models\Coupon::factory()->create([
        'organization_id' => $organization->id,
        'code' => 'SUMMER',
        'name' => 'Summer promo',
        'description' => 'Summer discount',
    ]);
    $appliedCoupon = App\Models\AppliedCoupon::factory()->create([
        'coupon_id' => $coupon->id,
        'customer_id' => $customer->id,
        'organization_id' => $organization->id,
        'amount_currency' => 'EUR',
    ]);
    $invoice = App\Models\Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'currency' => 'EUR',
    ]);
    $credit = Credit::factory()->create([
        'organization_id' => $organization->id,
        'invoice_id' => $invoice->id,
        'applied_coupon_id' => $appliedCoupon->id,
        'amount_cents' => 200,
        'amount_currency' => 'EUR',
        'before_taxes' => true,
    ]);

    $payload = (new CreditSerializer($credit))->serialize();

    expect($payload['lago_id'])->toBe($credit->id)
        ->and($payload['amount_cents'])->toBe(200)
        ->and($payload['amount_currency'])->toBe('EUR')
        ->and($payload['before_taxes'])->toBeTrue()
        ->and($payload['item'])->toBe([
            'lago_item_id' => $coupon->id,
            'type' => 'coupon',
            'code' => 'SUMMER',
            'name' => 'Summer promo',
            'description' => 'Summer discount',
        ])
        ->and($payload['invoice'])->toBe([
            'lago_id' => $invoice->id,
            'payment_status' => $invoice->paymentStatusEnum()?->label(),
        ]);
})->group('ledger:ser:V1.CreditSerializer');
