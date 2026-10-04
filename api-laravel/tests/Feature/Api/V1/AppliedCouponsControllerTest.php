<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/applied_coupons',
    'ledger:rest:GET:/api/v1/applied_coupons',
);

use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\AppliedCoupon;
use Illuminate\Support\Facades\DB;

/**
 * Ports of Rails' spec/requests/api/v1/applied_coupons_controller_spec.rb
 * and the "a applied coupon index endpoint" shared example
 * (spec/support/shared_examples/applied_coupon_index.rb).
 */
function appliedCouponEndpointOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

/**
 * The shared "a applied coupon index endpoint" fixture: two applied coupons
 * for one customer, the first carrying an active credit (amount_cents 10
 * → 8 remaining).
 *
 * @return array<string, mixed>
 */
function appliedCouponIndexFixture(object $organization): array
{
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $coupon1 = Coupon::factory()->create(['organization_id' => $organization->id]);
    $coupon2 = Coupon::factory()->create(['organization_id' => $organization->id]);

    $appliedCoupon1 = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon1->id,
        'amount_cents' => 10,
        'amount_currency' => $customer->currency,
        // Distinct timestamps: the consistent ordering is created_at desc,
        // id asc, and same-second creates would tie on the first key.
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

    return [
        'customer' => $customer,
        'coupon_1' => $coupon1,
        'coupon_2' => $coupon2,
        'applied_coupon_1' => $appliedCoupon1,
        'applied_coupon_2' => $appliedCoupon2,
    ];
}

// -- POST /api/v1/applied_coupons ---------------------------------------------------

it('applies a coupon to a customer', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    Subscription::factory()->create(['customer_id' => $customer->id]);

    $this->postJson('/api/v1/applied_coupons', ['applied_coupon' => [
        'external_customer_id' => $customer->external_id,
        'coupon_code' => $coupon->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($customer, $coupon): void {
            $json->has('applied_coupon.lago_id')
                ->where('applied_coupon.lago_coupon_id', $coupon->id)
                ->where('applied_coupon.lago_customer_id', $customer->id)
                ->where('applied_coupon.external_customer_id', $customer->external_id)
                ->where('applied_coupon.amount_cents', $coupon->amount_cents)
                ->where('applied_coupon.amount_currency', $coupon->amount_currency)
                ->where('applied_coupon.expiration_at', null)
                ->has('applied_coupon.created_at')
                ->where('applied_coupon.terminated_at', null)
                ->etc();
        });

    // The fixed-amount coupon's currency propagates onto the customer.
    expect($customer->refresh()->currency)->toBe($coupon->amount_currency);
});

it('returns not_found with invalid applied coupon params', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();

    $this->postJson('/api/v1/applied_coupons', ['applied_coupon' => [
        'name' => 'Foo Bar',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- GET /api/v1/applied_coupons ----------------------------------------------------

it('returns applied coupons with credits included', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    $fixture = appliedCouponIndexFixture($organization);

    $this->getJson('/api/v1/applied_coupons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($fixture): void {
        $json->has('applied_coupons', 2)
            // Rails: consistent ordering — created_at desc, id asc.
            ->where('applied_coupons.0.lago_id', $fixture['applied_coupon_2']->id)
            ->where('applied_coupons.1.lago_id', $fixture['applied_coupon_1']->id)
            ->where('applied_coupons.1.amount_cents', $fixture['applied_coupon_1']->amount_cents)
            ->where('applied_coupons.1.amount_cents_remaining', 8)
            ->where('meta.current_page', 1)
            ->where('meta.next_page', null)
            ->where('meta.prev_page', null)
            ->where('meta.total_pages', 1)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

it('paginates applied coupons', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    $fixture = appliedCouponIndexFixture($organization);

    $this->getJson('/api/v1/applied_coupons?page=2&per_page=1', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($fixture): void {
        $json->has('applied_coupons', 1)
            ->where('applied_coupons.0.lago_id', $fixture['applied_coupon_1']->id)
            ->where('meta.current_page', 2)
            ->where('meta.next_page', null)
            ->where('meta.prev_page', 1)
            ->where('meta.total_pages', 2)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

it('filters applied coupons by status', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    $fixture = appliedCouponIndexFixture($organization);

    $this->getJson('/api/v1/applied_coupons?status=active', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('meta.total_count', 2);

    $this->getJson('/api/v1/applied_coupons?status=terminated', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('applied_coupons', []);
});

it('filters applied coupons by coupon_code', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    $fixture = appliedCouponIndexFixture($organization);

    $this->getJson('/api/v1/applied_coupons?coupon_code[]='.$fixture['coupon_1']->code, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($fixture): void {
        $json->has('applied_coupons', 1)
            ->where('applied_coupons.0.lago_id', $fixture['applied_coupon_1']->id)
            ->etc();
    });
});

it('returns the applied coupon of a deleted coupon', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    $fixture = appliedCouponIndexFixture($organization);

    // Rails trait :deleted + :terminated — the applied coupon survives the
    // parent coupon's discard and stays listable.
    $fixture['coupon_1']->delete();
    $fixture['applied_coupon_1']->markAsTerminated();

    $this->getJson('/api/v1/applied_coupons?coupon_code[]='.$fixture['coupon_1']->code, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('applied_coupons.0.lago_id', $fixture['applied_coupon_1']->id);
});

it('filters applied coupons by external_customer_id', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    $fixture = appliedCouponIndexFixture($organization);

    $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $otherCustomer->id,
        'coupon_id' => $fixture['coupon_1']->id,
    ]);

    $this->getJson('/api/v1/applied_coupons?external_customer_id='.$fixture['customer']->external_id, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->has('applied_coupons', 2)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

it('returns an empty collection when no applied coupon matches the external_customer_id', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    appliedCouponIndexFixture($organization);

    $this->getJson('/api/v1/applied_coupons?external_customer_id=non_existent_id', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('applied_coupons', []);
});

it('excludes applied coupons of deleted customers', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    $fixture = appliedCouponIndexFixture($organization);

    // Rails: base scope joins(:customer).where(customers: {deleted_at: nil}).
    $fixture['customer']->delete();

    $this->getJson('/api/v1/applied_coupons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('applied_coupons', []);
});

// -- v2 mirror ----------------------------------------------------------------------

it('mirrors the applied coupon endpoints on v2 with the beta header', function (): void {
    [$organization, $apiKey] = appliedCouponEndpointOrganization();
    appliedCouponIndexFixture($organization);

    $this->getJson('/api/v2/applied_coupons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 2);
});

// -- api permissions ---------------------------------------------------------------------

it('requires an api permission to write applied coupons', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = appliedCouponEndpointOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['applied_coupon' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/applied_coupons', ['applied_coupon' => [
        'external_customer_id' => 'unknown',
        'coupon_code' => 'unknown',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'write_action_not_allowed_for_applied_coupon',
        ]);
});

it('requires an api permission to read applied coupons', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = appliedCouponEndpointOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['applied_coupon' => ['write']]), $apiKey->id],
    );

    $this->getJson('/api/v1/applied_coupons', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_applied_coupon',
        ]);
});
