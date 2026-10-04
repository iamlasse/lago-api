<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/../Resolvers/CouponsResolverTest.php';

use App\Models\Plan;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\AppliedCoupon;

/**
 * Ports of Rails' spec/graphql/mutations/coupons/{create,update,destroy,
 * terminate}_spec.rb and spec/graphql/mutations/applied_coupons/
 * {create,terminate}_spec.rb over the frozen SDL.
 *
 * Ledger rows: gql:mutation:createCoupon, gql:mutation:updateCoupon,
 * gql:mutation:destroyCoupon, gql:mutation:terminateCoupon,
 * gql:mutation:createAppliedCoupon, gql:mutation:terminateAppliedCoupon.
 */
const CREATE_COUPON_MUTATION = <<<'GQL'
mutation($input: CreateCouponInput!) {
    createCoupon(input: $input) {
        id
        name
        code
        description
        amountCents
        amountCurrency
        expiration
        expirationAt
        status
        limitedPlans
        plans { id }
        reusable
    }
}
GQL;

const UPDATE_COUPON_MUTATION = <<<'GQL'
mutation($input: UpdateCouponInput!) {
    updateCoupon(input: $input) {
        id
        name
        code
        description
        status
        amountCents
        amountCurrency
        expiration
        expirationAt
        limitedPlans
        plans { id }
        reusable
    }
}
GQL;

const DESTROY_COUPON_MUTATION = <<<'GQL'
mutation($input: DestroyCouponInput!) {
    destroyCoupon(input: $input) { id }
}
GQL;

const TERMINATE_COUPON_MUTATION = <<<'GQL'
mutation($input: TerminateCouponInput!) {
    terminateCoupon(input: $input) {
        id name status terminatedAt
    }
}
GQL;

const CREATE_APPLIED_COUPON_MUTATION = <<<'GQL'
mutation($input: CreateAppliedCouponInput!) {
    createAppliedCoupon(input: $input) {
        coupon { id }
        id
        amountCents
        amountCurrency
        createdAt
    }
}
GQL;

const TERMINATE_APPLIED_COUPON_MUTATION = <<<'GQL'
mutation($input: TerminateAppliedCouponInput!) {
    terminateAppliedCoupon(input: $input) {
        id terminatedAt
    }
}
GQL;

it('returns unauthorized on createCoupon without a token', function (): void {
    // The input is irrelevant: the guard rejects the unauthenticated caller
    // well-formed input so the unauthorized error is the one surfacing.
    $response = gqlPost(
        CREATE_COUPON_MUTATION,
        ['input' => [
            'name' => 'Super Coupon',
            'code' => 'free-beer',
            'couponType' => 'fixed_amount',
            'frequency' => 'once',
            'expiration' => 'no_expiration',
        ]],
    );

    expect($response->json('errors.0.message'))->toBe('unauthorized');
});

it('creates a coupon', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $expirationAt = now()->addDays(3);

    $response = gqlPost(
        CREATE_COUPON_MUTATION,
        ['input' => [
            'name' => 'Super Coupon',
            'code' => 'free-beer',
            'description' => 'This is a description',
            'couponType' => 'fixed_amount',
            'frequency' => 'once',
            'amountCents' => 5000,
            'amountCurrency' => 'EUR',
            'expiration' => 'time_limit',
            'expirationAt' => $expirationAt->toIso8601String(),
            'reusable' => false,
            'appliesTo' => ['planIds' => [$plan->id]],
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.createCoupon');

    expect($payload['id'])->toBeString()
        ->and($payload['name'])->toBe('Super Coupon')
        ->and($payload['code'])->toBe('free-beer')
        ->and($payload['description'])->toBe('This is a description')
        ->and($payload['amountCents'])->toBe('5000')
        ->and($payload['amountCurrency'])->toBe('EUR')
        ->and($payload['expiration'])->toBe('time_limit')
        ->and($payload['expirationAt'])->toBeString()
        ->and($payload['status'])->toBe('active')
        ->and($payload['limitedPlans'])->toBeTrue()
        ->and($payload['plans'][0]['id'])->toBe($plan->id)
        ->and($payload['reusable'])->toBeFalse();

    expect(Coupon::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

it('answers the validation envelope when the created coupon is invalid', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $response = gqlPost(
        CREATE_COUPON_MUTATION,
        ['input' => [
            'name' => 'Super Coupon',
            'code' => 'free-beer',
            'couponType' => 'percentage',
            'frequency' => 'once',
            'expiration' => 'no_expiration',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $error = $response->json('errors.0.extensions');

    expect($error['status'])->toBe(422)
        ->and($error['code'])->toBe('unprocessable_entity')
        ->and($error['details']['percentageRate'])->toBe(['value_is_mandatory']);
});

it('updates a coupon', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        UPDATE_COUPON_MUTATION,
        ['input' => [
            'id' => $coupon->id,
            'name' => 'New name',
            'couponType' => 'fixed_amount',
            'frequency' => 'once',
            'code' => 'new_code',
            'description' => 'This is a description',
            'amountCents' => 123,
            'amountCurrency' => 'USD',
            'expiration' => 'time_limit',
            'expirationAt' => now()->addDays(3)->toIso8601String(),
            'reusable' => false,
            'appliesTo' => ['planIds' => [$plan->id]],
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.updateCoupon');

    expect($payload['id'])->toBe($coupon->id)
        ->and($payload['name'])->toBe('New name')
        ->and($payload['code'])->toBe('new_code')
        ->and($payload['description'])->toBe('This is a description')
        ->and($payload['amountCents'])->toBe('123')
        ->and($payload['amountCurrency'])->toBe('USD')
        ->and($payload['limitedPlans'])->toBeTrue()
        ->and($payload['plans'][0]['id'])->toBe($plan->id)
        ->and($payload['reusable'])->toBeFalse();
});

it('keeps the immutable attributes of an already-applied coupon', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 500,
        'amount_currency' => 'EUR',
    ]);

    AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);

    $response = gqlPost(
        UPDATE_COUPON_MUTATION,
        ['input' => [
            'id' => $coupon->id,
            'name' => 'New name',
            'couponType' => 'fixed_amount',
            'frequency' => 'once',
            'amountCents' => 999,
            'amountCurrency' => 'USD',
            'expiration' => 'no_expiration',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.updateCoupon');

    // The name updates; the amount stays the applied one.
    expect($payload['name'])->toBe('New name')
        ->and($payload['amountCents'])->toBe('500')
        ->and($payload['amountCurrency'])->toBe('EUR');

    expect($coupon->refresh()->amount_cents)->toBe(500);
});

it('destroys a coupon', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        DESTROY_COUPON_MUTATION,
        ['input' => ['id' => $coupon->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    expect($response->json('data.destroyCoupon.id'))->toBe($coupon->id);

    // Soft delete: the row survives with a deleted_at.
    expect(Coupon::withTrashed()->find($coupon->id)->deleted_at)->not->toBeNull();
});

it('terminates a coupon', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        TERMINATE_COUPON_MUTATION,
        ['input' => ['id' => $coupon->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.terminateCoupon');

    expect($payload['id'])->toBe($coupon->id)
        ->and($payload['name'])->toBe($coupon->name)
        ->and($payload['status'])->toBe('terminated')
        ->and($payload['terminatedAt'])->toBeString();
});

it('assigns a coupon to a customer', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        CREATE_APPLIED_COUPON_MUTATION,
        ['input' => [
            'couponId' => $coupon->id,
            'customerId' => $customer->id,
            'frequency' => 'once',
            'amountCents' => 123,
            'amountCurrency' => 'EUR',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.createAppliedCoupon');

    expect($payload['id'])->toBeString()
        ->and($payload['coupon']['id'])->toBe($coupon->id)
        ->and($payload['amountCents'])->toBe('123')
        ->and($payload['amountCurrency'])->toBe('EUR')
        ->and($payload['createdAt'])->toBeString();

    expect(AppliedCoupon::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('terminates an applied coupon', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $coupon = Coupon::factory()->create(['organization_id' => $organization->id]);
    $appliedCoupon = AppliedCoupon::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'coupon_id' => $coupon->id,
    ]);

    $response = gqlPost(
        TERMINATE_APPLIED_COUPON_MUTATION,
        ['input' => ['id' => $appliedCoupon->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.terminateAppliedCoupon');

    expect($payload['id'])->toBe($appliedCoupon->id)
        ->and($payload['terminatedAt'])->toBeString();
});

it('answers not_found when the applied coupon is unknown', function (): void {
    [$organization, $user] = gqlCouponsSetup();

    $response = gqlPost(
        TERMINATE_APPLIED_COUPON_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    $error = $response->json('errors.0.extensions');

    expect($error['status'])->toBe(404)
        ->and($error['code'])->toBe('not_found');
});
