<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/coupons',
    'ledger:rest:GET:/api/v1/coupons',
    'ledger:rest:GET:/api/v1/coupons/:code',
    'ledger:rest:PUT:/api/v1/coupons/:code',
    'ledger:rest:DELETE:/api/v1/coupons/:code',
);

use App\Models\Coupon;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\BillableMetric;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' spec/requests/api/v1/coupons_controller_spec.rb — a coupon
 * is keyed by its code (Rails: resources :coupons, param: :code).
 */
function couponEndpointOrganization(): array
{
    $organization = Organization::factory()->create();

    return [$organization, $organization->apiKeys()->first()];
}

// -- POST /api/v1/coupons ---------------------------------------------------------

it('creates a coupon', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $billableMetric = BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $expirationAt = now()->addDays(15);

    $createParams = [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
        'expiration' => 'time_limit',
        'expiration_at' => $expirationAt->toIso8601String(),
        'reusable' => false,
        'applies_to' => [
            'billable_metric_codes' => [$billableMetric->code],
        ],
    ];

    $this->postJson('/api/v1/coupons', ['coupon' => $createParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($createParams, $expirationAt, $billableMetric): void {
        $json->has('coupon.lago_id')
            ->where('coupon.code', $createParams['code'])
            ->where('coupon.name', $createParams['name'])
            ->has('coupon.created_at')
            ->where('coupon.expiration_at', fn (string $value) => str_starts_with($expirationAt->isoFormat('YYYY-MM-DDTHH:mm'), mb_substr($value, 0, 16)))
            ->where('coupon.reusable', false)
            ->where('coupon.limited_billable_metrics', true)
            ->where('coupon.billable_metric_codes.0', $billableMetric->code)
            ->etc();
    });

    expect(Coupon::count())->toBe(1);

    $coupon = Coupon::first();
    expect($coupon->couponTargets()->whereNotNull('billable_metric_id')->count())->toBe(1);
});

it('rejects a percentage coupon without a percentage rate', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->postJson('/api/v1/coupons', ['coupon' => [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'percentage',
        'frequency' => 'once',
        'expiration' => 'no_expiration',
        'reusable' => true,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_errors');
});

it('rejects a fixed_amount coupon without an amount', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->postJson('/api/v1/coupons', ['coupon' => [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'expiration' => 'no_expiration',
        'reusable' => true,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.amount_cents.0', 'value_is_mandatory');
});

it('rejects an invalid expiration_at date', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->postJson('/api/v1/coupons', ['coupon' => [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'amount_cents' => 100,
        'amount_currency' => 'EUR',
        'expiration' => 'time_limit',
        'expiration_at' => 'not-a-date',
        'reusable' => true,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.expiration_at.0', 'invalid_date');
});

it('answers not_found when a limited plan code is unknown', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->postJson('/api/v1/coupons', ['coupon' => [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'amount_cents' => 100,
        'amount_currency' => 'EUR',
        'expiration' => 'no_expiration',
        'reusable' => true,
        'applies_to' => ['plan_codes' => ['nope']],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('answers not_found when a limited billable metric code is unknown', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->postJson('/api/v1/coupons', ['coupon' => [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'amount_cents' => 100,
        'amount_currency' => 'EUR',
        'expiration' => 'no_expiration',
        'reusable' => true,
        'applies_to' => ['billable_metric_codes' => ['nope']],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- PUT /api/v1/coupons/:code ------------------------------------------------------

it('updates a coupon', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    $expirationAt = now()->addDays(15);

    $updateParams = [
        'name' => 'coupon1',
        'code' => $coupon->code,
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
        'expiration' => 'time_limit',
        'expiration_at' => $expirationAt->toIso8601String(),
    ];

    $this->putJson('/api/v1/coupons/'.$coupon->code, ['coupon' => $updateParams], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($coupon): void {
        $json->where('coupon.lago_id', $coupon->id)
            ->where('coupon.code', $coupon->code)
            ->etc();
    });
});

it('returns not_found when the updated coupon does not exist', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->putJson('/api/v1/coupons/'.Str::uuid(), ['coupon' => [
        'name' => 'coupon1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns unprocessable_entity when the updated code already exists', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $anotherCoupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $this->putJson('/api/v1/coupons/'.$coupon->code, ['coupon' => [
        'code' => $anotherCoupon->code,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable();
});

// -- GET /api/v1/coupons/:code ------------------------------------------------------

it('returns a coupon', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/coupons/'.$coupon->code, [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('coupon.lago_id', $coupon->id)
        ->assertJsonPath('coupon.code', $coupon->code);
});

it('returns not_found when the coupon does not exist', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->getJson('/api/v1/coupons/'.Str::uuid(), [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- DELETE /api/v1/coupons/:code ---------------------------------------------------

it('deletes a coupon', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $this->deleteJson('/api/v1/coupons/'.$coupon->code, [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJsonPath('coupon.lago_id', $coupon->id);

    expect(Coupon::count())->toBe(0);
});

it('returns not_found when the deleted coupon does not exist', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $this->deleteJson('/api/v1/coupons/'.Str::uuid(), [], [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertNotFound();
});

// -- GET /api/v1/coupons ---------------------------------------------------------

it('returns coupons', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/coupons', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($coupon): void {
        $json->has('coupons', 1)
            ->where('coupons.0.lago_id', $coupon->id)
            ->where('coupons.0.code', $coupon->code)
            ->etc();
    });
});

it('returns coupons with pagination metadata', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    Coupon::factory()->count(2)->create(['organization_id' => $organization->id]);

    $this->getJson('/api/v1/coupons?page=1&per_page=1', [
        'Authorization' => 'Bearer '.$apiKey->value,
    ])->assertOk()->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
        $json->has('coupons', 1)
            ->where('meta.current_page', 1)
            ->where('meta.next_page', 2)
            ->where('meta.prev_page', null)
            ->where('meta.total_pages', 2)
            ->where('meta.total_count', 2)
            ->etc();
    });
});

// -- v2 mirror ----------------------------------------------------------------------

it('mirrors the coupon endpoints on v2 with the beta header', function (): void {
    [$organization, $apiKey] = couponEndpointOrganization();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $headers = ['Authorization' => 'Bearer '.$apiKey->value];

    $this->getJson('/api/v2/coupons', $headers)
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('coupons.0.code', $coupon->code);

    $this->getJson('/api/v2/coupons/not_a_coupon', $headers)
        ->assertNotFound()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta');
});

// -- api permissions ---------------------------------------------------------------------

it('requires an api permission to write coupons', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = couponEndpointOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['coupon' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/coupons', ['coupon' => [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
        'expiration' => 'no_expiration',
        'reusable' => true,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'write_action_not_allowed_for_coupon',
        ]);
});

it('allows the coupon write when the api permission grants it', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = couponEndpointOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['coupon' => ['write']]), $apiKey->id],
    );

    $this->postJson('/api/v1/coupons', ['coupon' => [
        'name' => 'coupon1',
        'code' => 'coupon1_code',
        'coupon_type' => 'fixed_amount',
        'frequency' => 'once',
        'amount_cents' => 123,
        'amount_currency' => 'EUR',
        'expiration' => 'no_expiration',
        'reusable' => true,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('coupon.code', 'coupon1_code');
});

it('requires an api permission to read coupons', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = couponEndpointOrganization();

    DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['coupon' => ['write']]), $apiKey->id],
    );

    $this->getJson('/api/v1/coupons', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertExactJson([
            'status' => 403,
            'error' => 'Forbidden',
            'code' => 'read_action_not_allowed_for_coupon',
        ]);
});
