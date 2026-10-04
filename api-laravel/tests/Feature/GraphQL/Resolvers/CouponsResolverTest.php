<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\AppliedCoupon;
use App\Models\BillingEntity;

/**
 * Ports of Rails' spec/graphql/resolvers/{coupons_resolver,
 * applied_coupons_resolver}_spec.rb over the frozen SDL.
 *
 * Ledger rows: gql:query:coupons, gql:query:appliedCoupons.
 */
function gqlCouponsSetup(): array
{
    $organization = gqlCreateOrganization();
    // Rails creates the default billing entity with the organization
    // (Organizations::CreateService); the frozen-schema port creates it
    // explicitly in the fixture.
    BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const COUPONS_LIST_QUERY = <<<'GQL'
query($status: CouponStatusEnum) {
    coupons(limit: 5, status: $status) {
        collection { id code status }
        metadata { currentPage totalCount }
    }
}
GQL;

it('returns unauthorized on coupons without a token', function (): void {
    $response = gqlPost(COUPONS_LIST_QUERY);

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
});

it('returns unauthorized on appliedCoupons without a token', function (): void {
    $response = gqlPost(APPLIED_COUPONS_LIST_QUERY);

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
});

it('returns a list of coupons', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $active = Coupon::factory()->create(['organization_id' => $organization->id]);
    Coupon::factory()->terminated()->create(['organization_id' => $organization->id]);

    $response = gqlPost(COUPONS_LIST_QUERY, ['status' => 'active'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.coupons');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($active->id)
        ->and($payload['collection'][0]['code'])->toBe($active->code)
        ->and($payload['collection'][0]['status'])->toBe('active')
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(1);
});

it('answers not_found for an unknown coupon id', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $response = gqlPost(<<<'GQL'
        query {
            coupon(id: "00000000-0000-0000-0000-000000000000") { id }
        }
        GQL, [], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $error = $response->json('errors.0.extensions');
    expect($error['status'])->toBe(404)
        ->and($error['code'])->toBe('not_found')
        ->and($error['details']['coupon'])->toBe(['not_found']);
});

it('resolves a discarded coupon through the coupon query', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    // Rails: current_organization.coupons.with_discarded.find(id).
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    $coupon->delete();

    $response = gqlPost(<<<GQL
        query {
            coupon(id: "{$coupon->id}") { id code }
        }
        GQL, [], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('data.coupon.id'))->toBe($coupon->id)
        ->and($response->json('data.coupon.code'))->toBe($coupon->code);
});

it('resolves the coupon computed fields', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->percentage()->reusable()->create([
        'organization_id' => $organization->id,
    ]);

    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);

    $response = gqlPost(<<<GQL
        query {
            coupon(id: "{$coupon->id}") {
                id
                couponType
                frequency
                expiration
                status
                percentageRate
                appliedCouponsCount
                customersCount
                limitedPlans
                limitedBillableMetrics
            }
        }
        GQL, [], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.coupon');

    expect($payload['couponType'])->toBe('percentage')
        ->and($payload['frequency'])->toBe('once')
        ->and($payload['expiration'])->toBe('no_expiration')
        ->and($payload['status'])->toBe('active')
        // PHP json-encodes the float 20.0 as 20.
        ->and($payload['percentageRate'])->toBe(20)
        ->and($payload['appliedCouponsCount'])->toBe(1)
        ->and($payload['customersCount'])->toBe(1)
        ->and($payload['limitedPlans'])->toBe(false)
        ->and($payload['limitedBillableMetrics'])->toBe(false);
});

const APPLIED_COUPONS_LIST_QUERY = <<<'GQL'
query($status: AppliedCouponStatusEnum, $externalCustomerId: String, $couponCode: [String!]) {
    appliedCoupons(limit: 5, status: $status, externalCustomerId: $externalCustomerId, couponCode: $couponCode) {
        collection { id amountCents amountCurrency frequency }
        metadata { currentPage totalCount }
    }
}
GQL;

it('returns a list of applied coupons', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $appliedCoupon = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);
    AppliedCoupon::factory()->terminated()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);

    $response = gqlPost(APPLIED_COUPONS_LIST_QUERY, ['status' => 'active'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.appliedCoupons');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($appliedCoupon->id)
        ->and($payload['collection'][0]['amountCents'])->toBe('1000')
        ->and($payload['collection'][0]['amountCurrency'])->toBe('EUR')
        ->and($payload['collection'][0]['frequency'])->toBe('once')
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(1);
});

it('filters applied coupons by external customer id', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);
    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $otherCustomer->id,
        'coupon_id' => $coupon->id,
    ]);

    $response = gqlPost(
        APPLIED_COUPONS_LIST_QUERY,
        ['externalCustomerId' => $customer->external_id],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.appliedCoupons');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBeString();
});

it('filters applied coupons by coupon code', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    $otherCoupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);
    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $otherCoupon->id,
    ]);

    $response = gqlPost(
        APPLIED_COUPONS_LIST_QUERY,
        ['couponCode' => [$coupon->code]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    expect($response->json('data.appliedCoupons.metadata.totalCount'))->toBe(1);
});
