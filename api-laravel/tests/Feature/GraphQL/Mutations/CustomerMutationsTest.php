<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/../Resolvers/CustomersResolverTest.php';

/**
 * Ports of Rails' spec/graphql/mutations/customers/{create,update,destroy}
 * _spec.rb — the customer mutations over the frozen SDL.
 *
 * Ledger rows: gql:mutation:createCustomer, gql:mutation:updateCustomer,
 * gql:mutation:destroyCustomer.
 */
function gqlCreateCustomerViaMutation(object $user, object $organization, array $input): Illuminate\Testing\TestResponse
{
    return gqlPost(
        <<<'GQL'
        mutation($input: CreateCustomerInput!) {
            createCustomer(input: $input) {
                id
                externalId
                name
                email
                currency
                country
                city
                displayName
                applicableTimezone
            }
        }
        GQL,
        ['input' => $input],
        gqlAuthHeaders($user, $organization->id),
    );
}

it('creates a customer', function () {
    [$organization, $user] = gqlCustomersSetup();

    $response = gqlCreateCustomerViaMutation($user, $organization, [
        'externalId' => 'customer-1',
        'name' => 'Acme',
        'email' => 'billing@acme.test',
        'currency' => 'EUR',
        'country' => 'FR',
        'city' => 'Paris',
    ]);

    $payload = $response->json('data.createCustomer');

    expect($payload['externalId'])->toBe('customer-1')
        ->and($payload['name'])->toBe('Acme')
        ->and($payload['email'])->toBe('billing@acme.test')
        ->and($payload['currency'])->toBe('EUR')
        ->and($payload['country'])->toBe('FR')
        ->and($payload['city'])->toBe('Paris');

    $customer = App\Models\Customer::query()
        ->where('organization_id', $organization->id)
        ->where('external_id', 'customer-1')
        ->first();

    expect($customer)->not->toBeNull()
        ->and($customer->id)->toBe($payload['id'])
        // The customer joins the organization's default billing entity.
        ->and($customer->billing_entity_id)->toBe($organization->defaultBillingEntity?->id);
})->group('ledger:gql:mutation:createCustomer');

it('returns the validation error envelope for an invalid email', function () {
    [$organization, $user] = gqlCustomersSetup();

    $response = gqlCreateCustomerViaMutation($user, $organization, [
        'externalId' => 'customer-2',
        'email' => 'not-an-email',
    ]);

    expect($response->json('data.createCustomer'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Unprocessable Entity')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 422,
            'code' => 'unprocessable_entity',
            'details' => ['email' => ['invalid_email_format']],
        ]);
})->group('ledger:gql:mutation:createCustomer');

it('updates an existing customer', function () {
    [$organization, $user] = gqlCustomersSetup();

    $customer = gqlMakeCustomer($organization, ['name' => 'Before', 'city' => 'Lyon']);

    $response = gqlPost(
        <<<'GQL'
        mutation($input: UpdateCustomerInput!) {
            updateCustomer(input: $input) {
                id
                name
                city
                externalId
            }
        }
        GQL,
        ['input' => ['id' => $customer->id, 'externalId' => $customer->external_id, 'name' => 'After', 'city' => 'Nice']],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.updateCustomer');

    expect($payload['id'])->toBe($customer->id)
        ->and($payload['name'])->toBe('After')
        ->and($payload['city'])->toBe('Nice')
        ->and($payload['externalId'])->toBe($customer->external_id);

    expect($customer->refresh()->name)->toBe('After');
})->group('ledger:gql:mutation:updateCustomer');

it('returns not_found when updating a customer of another organization', function () {
    [$organization, $user] = gqlCustomersSetup();

    $foreign = gqlMakeCustomer(gqlCreateOrganization('Other Corp'), ['name' => 'Foreign']);

    $response = gqlPost(
        'mutation($input: UpdateCustomerInput!) { updateCustomer(input: $input) { id } }',
        ['input' => ['id' => $foreign->id, 'externalId' => $foreign->external_id, 'name' => 'Hijacked']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions.status'))->toBe(404);
    expect($foreign->refresh()->name)->toBe('Foreign');
})->group('ledger:gql:mutation:updateCustomer');

it('destroys a customer (soft delete) and returns the payload id', function () {
    [$organization, $user] = gqlCustomersSetup();

    $customer = gqlMakeCustomer($organization);

    $response = gqlPost(
        'mutation($input: DestroyCustomerInput!) { destroyCustomer(input: $input) { id clientMutationId } }',
        ['input' => ['id' => $customer->id, 'clientMutationId' => 'mutation-1']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.destroyCustomer.id'))->toBe($customer->id)
        ->and($response->json('data.destroyCustomer.clientMutationId'))->toBe('mutation-1');

    // Soft delete: gone from the default scope, kept with withDeleted.
    expect($customer->refresh()->deleted_at)->not->toBeNull()
        ->and($organization->customers()->count())->toBe(0)
        ->and($organization->customers()->withTrashed()->count())->toBe(1);
})->group('ledger:gql:mutation:destroyCustomer');

it('returns not_found when destroying an unknown customer', function () {
    [$organization, $user] = gqlCustomersSetup();

    $response = gqlPost(
        'mutation($input: DestroyCustomerInput!) { destroyCustomer(input: $input) { id } }',
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['customer' => ['not_found']],
        ]);
})->group('ledger:gql:mutation:destroyCustomer');
