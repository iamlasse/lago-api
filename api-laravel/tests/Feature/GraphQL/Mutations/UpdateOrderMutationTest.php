<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Order;
use App\Models\Customer;

/**
 * Ports of Rails' spec/graphql/mutations/orders/update_spec.rb over the
 * frozen SDL.
 *
 * Ledger rows: gql:mutation:updateOrder.
 */
function gqlUpdateOrderSetup(): array
{
    [$organization, $user] = [gqlCreateOrganization(), gqlCreateUser()];
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlUpdateOrder(object $organization, array $attributes = []): Order
{
    // The orders table carries no subscription_id — the order rides on the
    // order form's quote version like Rails.
    return Order::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => Customer::factory()->create(['organization_id' => $organization->id])->id,
        'status' => 'created',
    ], $attributes));
}

const UPDATE_ORDER_MUTATION = <<<'GQL'
mutation($input: UpdateOrderInput!) {
    updateOrder(input: $input) { id status executionMode }
}
GQL;

it("updates the order's execution settings", function (): void {
    [$organization, $user] = gqlUpdateOrderSetup();
    // Order forms are premium feature-flag gated.
    config(['lago.license' => 'premium-license-token']);
    $organization->feature_flags = ['order_forms'];
    $organization->save();

    $order = gqlUpdateOrder($organization);

    $response = gqlPost(
        UPDATE_ORDER_MUTATION,
        ['input' => ['id' => $order->id, 'executionMode' => 'order_only']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateOrder.id'))->toBe($order->id)
        ->and($response->json('data.updateOrder.executionMode'))->toBe('order_only')
        ->and($order->refresh()->execution_mode?->value)->toBe('order_only');

    config(['lago.license' => null]);
})->group('ledger:gql:mutation:updateOrder');

it('answers the order not_found envelope for an unknown order', function (): void {
    [$organization, $user] = gqlUpdateOrderSetup();

    $response = gqlPost(
        UPDATE_ORDER_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000', 'executionMode' => 'order_only']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['order' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:updateOrder');

it('answers unauthorized on updateOrder without a token', function (): void {
    $response = gqlPost(
        UPDATE_ORDER_MUTATION,
        ['input' => ['id' => 'anything']],
    );

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:mutation:updateOrder');
