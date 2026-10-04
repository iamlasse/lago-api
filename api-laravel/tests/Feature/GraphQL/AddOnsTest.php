<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Order;
use App\Models\OrderForm;
use App\Models\QuoteVersion;

/**
 * Ports of Rails' spec/graphql/mutations/add_ons/*_spec.rb and the add-on
 * / order resolvers over the frozen SDL.
 *
 * Ledger rows: gql:query:addOn, gql:query:addOns,
 * gql:mutation:createAddOn, gql:mutation:updateAddOn,
 * gql:mutation:destroyAddOn, gql:query:order, gql:query:orders.
 */
function gqlAddOnsSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();

    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const CREATE_ADD_ON_MUTATION = <<<'GQL'
mutation($input: CreateAddOnInput!) {
    createAddOn(input: $input) {
        id
        code
        name
        invoiceDisplayName
        amountCents
        amountCurrency
        taxes { code }
    }
}
GQL;

const ADD_ONS_QUERY = <<<'GQL'
query($page: Int, $limit: Int, $searchTerm: String) {
    addOns(page: $page, limit: $limit, searchTerm: $searchTerm) {
        collection { id code name appliedAddOnsCount customersCount taxes { code } }
        metadata { currentPage totalPages totalCount }
    }
}
GQL;

const ADD_ON_QUERY = <<<'GQL'
query($id: ID!) {
    addOn(id: $id) { id code name }
}
GQL;

const UPDATE_ADD_ON_MUTATION = <<<'GQL'
mutation($input: UpdateAddOnInput!) {
    updateAddOn(input: $input) { id code name }
}
GQL;

const DESTROY_ADD_ON_MUTATION = <<<'GQL'
mutation($input: DestroyAddOnInput!) {
    destroyAddOn(input: $input) { id }
}
GQL;

const ORDERS_QUERY = <<<'GQL'
query {
    orders {
        collection {
            id
            number
            status
            orderType
            executionMode
            currency
            executionRecord { executionMode invoiceId errors }
            billingSnapshot
        }
        metadata { currentPage totalCount }
    }
}
GQL;

it('creates an add-on through createAddOn', function (): void {
    [$organization, $user] = gqlAddOnsSetup();

    $response = gqlPost(CREATE_ADD_ON_MUTATION, ['input' => [
        'name' => 'add_on1',
        'code' => 'add_on_code',
        'amountCents' => 123,
        'amountCurrency' => 'EUR',
        'invoiceDisplayName' => 'Addon 1 invoice name',
    ]], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.createAddOn');

    expect($payload['code'])->toBe('add_on_code')
        ->and($payload['amountCents'])->toBe('123');
});

it('answers validation errors from createAddOn with the error envelope', function (): void {
    [$organization, $user] = gqlAddOnsSetup();

    $input = ['name' => 'add_on1', 'code' => 'add_on_code', 'amountCents' => 123, 'amountCurrency' => 'EUR'];

    gqlPost(CREATE_ADD_ON_MUTATION, ['input' => $input], gqlAuthHeaders($user, $organization->id))->assertOk();

    $response = gqlPost(CREATE_ADD_ON_MUTATION, ['input' => $input], gqlAuthHeaders($user, $organization->id));

    // Rails: a validation failure surfaces as a GraphQL error.
    expect($response->json('errors'))->not->toBeNull();
});

it('lists add-ons through addOns with the search term', function (): void {
    [$organization, $user] = gqlAddOnsSetup();

    gqlPost(CREATE_ADD_ON_MUTATION, ['input' => [
        'name' => 'Seats addon',
        'code' => 'seats',
        'amountCents' => 500,
        'amountCurrency' => 'EUR',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk();

    $payload = gqlPost(ADD_ONS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.addOns');

    expect(count($payload['collection']))->toBe(1)
        ->and($payload['collection'][0]['code'])->toBe('seats')
        ->and($payload['collection'][0]['appliedAddOnsCount'])->toBe(0)
        ->and($payload['collection'][0]['customersCount'])->toBe(0);

    $miss = gqlPost(ADD_ONS_QUERY, ['searchTerm' => 'nope'], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.addOns');

    expect(count($miss['collection']))->toBe(0);
});

it('fetches, updates and destroys a single add-on', function (): void {
    [$organization, $user] = gqlAddOnsSetup();

    $id = gqlPost(CREATE_ADD_ON_MUTATION, ['input' => [
        'name' => 'add_on1',
        'code' => 'add_on_code',
        'amountCents' => 123,
        'amountCurrency' => 'EUR',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.createAddOn.id');

    expect(gqlPost(ADD_ON_QUERY, ['id' => $id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.addOn.code'))->toBe('add_on_code');

    // An unknown id answers the not_found error envelope.
    gqlPost(ADD_ON_QUERY, ['id' => Illuminate\Support\Str::uuid()], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');

    expect(gqlPost(UPDATE_ADD_ON_MUTATION, ['input' => [
        'id' => $id,
        'name' => 'renamed',
        'code' => 'add_on_code',
        'amountCents' => 123,
        'amountCurrency' => 'EUR',
    ]], gqlAuthHeaders($user, $organization->id))->assertOk()->json('data.updateAddOn.name'))->toBe('renamed');

    expect(gqlPost(DESTROY_ADD_ON_MUTATION, ['input' => ['id' => $id]], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.destroyAddOn.id'))->toBe($id);

    // A discarded add-on no longer resolves (the kept default scope).
    gqlPost(ADD_ON_QUERY, ['id' => $id], gqlAuthHeaders($user, $organization->id))
        ->assertOk()
        ->assertJsonPath('errors.0.extensions.code', 'not_found');
});

it('lists orders through orders with the execution record completed', function (): void {
    [$organization, $user] = gqlAddOnsSetup();

    $customer = App\Models\Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $quote = App\Models\Quote::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_type' => App\Models\Quote::ORDER_TYPES['one_off'],
    ]);

    $quoteVersion = QuoteVersion::factory()->create([
        'organization_id' => $organization->id,
        'quote_id' => $quote->id,
        'status' => 'approved',
        'approved_at' => now(),
        'currency' => 'EUR',
        'billing_items' => ['addOns' => []],
    ]);

    $orderForm = OrderForm::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'quote_version_id' => $quoteVersion->id,
        'status' => 'signed',
        'signed_at' => now(),
    ]);

    Order::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'order_form_id' => $orderForm->id,
        'status' => 'created',
        'execution_mode' => 'order_only',
    ]);

    $payload = gqlPost(ORDERS_QUERY, [], gqlAuthHeaders($user, $organization->id))
        ->assertOk()->json('data.orders');

    expect(count($payload['collection']))->toBe(1);

    $order = $payload['collection'][0];

    expect($order['number'])->toMatch('/^OR-\d{4}-\d{4}$/')
        ->and($order['status'])->toBe('created')
        ->and($order['orderType'])->toBe('one_off')
        ->and($order['executionMode'])->toBe('order_only')
        ->and($order['currency'])->toBe('EUR')
        // The execution record is completed with the defaults.
        ->and($order['executionRecord']['invoiceId'])->toBeNull()
        ->and($order['executionRecord']['errors'])->toBe([])
        ->and($order['billingSnapshot'])->toBe(['addOns' => []]);
});
