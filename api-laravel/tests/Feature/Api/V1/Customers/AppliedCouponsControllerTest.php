<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:GET:/api/v1/customers/:external_id/applied_coupons',
    'ledger:rest:DELETE:/api/v1/customers/:external_id/applied_coupons/:id',
);

use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\AppliedCoupon;

/**
 * Port of Rails'
 * spec/requests/api/v1/customers/applied_coupons_controller_spec.rb (plus
 * the "a applied coupon index endpoint" shared example for the index).
 */
function customerAppliedCouponEndpointOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

// -- GET /api/v1/customers/:external_id/applied_coupons -----------------------------

it('returns the customer applied coupons', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon1 = Coupon::factory()->create(['organization_id' => $organization->id]);
    $coupon2 = Coupon::factory()->create(['organization_id' => $organization->id]);

    $appliedCoupon1 = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon1->id,
        'amount_cents' => 10,
        'amount_currency' => $customer->currency,
        'created_at' => now()->subSecond(),
    ]);
    $appliedCoupon2 = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon2->id,
        'amount_cents' => 10,
        'amount_currency' => $customer->currency,
    ]);

    Credit::factory()->create([
        'applied_coupon_id' => $appliedCoupon1->id,
        'organization_id' => $organization->id,
        'amount_cents' => 2,
        'amount_currency' => $customer->currency,
    ]);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($appliedCoupon1, $appliedCoupon2): void {
        $json->has('applied_coupons', 2)
            ->where('applied_coupons.0.lago_id', $appliedCoupon2->id)
            ->where('applied_coupons.1.lago_id', $appliedCoupon1->id)
            ->where('applied_coupons.1.amount_cents_remaining', 8)
            ->where('meta.current_page', 1)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

it('paginates the customer applied coupons', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $appliedCoupon1 = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
        'created_at' => now()->subSecond(),
    ]);
    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons?page=2&per_page=1', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('applied_coupons.0.lago_id', $appliedCoupon1->id)
        ->assertJsonPath('meta.total_count', 2);
});

it('filters the customer applied coupons by status', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    AppliedCoupon::factory()->count(2)->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons?status=active', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('meta.total_count', 2);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons?status=terminated', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('applied_coupons', []);
});

it('returns not_found when the customer external_id is unknown', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $this->getJson('/api/v1/customers/unknown/applied_coupons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

it('returns not_found when the customer belongs to another organization', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create();

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- DELETE /api/v1/customers/:external_id/applied_coupons/:id ----------------------

it('terminates the applied coupon', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $appliedCoupon = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons/'.$appliedCoupon->id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('applied_coupon.lago_id', $appliedCoupon->id);

    expect($appliedCoupon->refresh()->statusEnum()?->label())->toBe('terminated');
});

it('returns not_found when the customer does not exist', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $this->deleteJson('/api/v1/customers/'.Str::uuid().'/applied_coupons/'.Str::uuid(), [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

it('returns not_found when the applied coupon does not exist', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons/'.Str::uuid(), [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

it('returns not_found when the coupon is not applied to the customer', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $otherAppliedCoupon = AppliedCoupon::factory()->create();

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/applied_coupons/'.$otherAppliedCoupon->id, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- v2 mirror ----------------------------------------------------------------------

it('mirrors the nested applied coupon endpoints on v2 with the beta header', function (): void {
    [$organization, $apiKey] = customerAppliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $appliedCoupon = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ]);

    $headers = ['Authorization' => 'Bearer '.$apiKey->value];

    $this->getJson('/api/v2/customers/'.$customer->external_id.'/applied_coupons', $headers)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 1);

    $this->deleteJson('/api/v2/customers/'.$customer->external_id.'/applied_coupons/'.$appliedCoupon->id, [], $headers)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});
