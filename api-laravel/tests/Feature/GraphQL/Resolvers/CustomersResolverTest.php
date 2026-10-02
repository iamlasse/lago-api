<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Customer;
use App\Models\Organization;

/**
 * Port of Rails' spec/graphql/resolvers/customers_resolver_spec.rb (scenarios
 * for the filters this slice ports) and customer_resolver_spec.rb.
 *
 * Ledger rows: gql:query:customers, gql:query:customer.
 */
function gqlCustomersSetup(): array
{
    $organization = gqlCreateOrganization();
    // Rails creates the default billing entity with the organization
    // (Organizations::CreateService); the frozen-schema port creates it
    // explicitly in the fixture.
    App\Models\BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlMakeCustomer(Organization $organization, array $attributes = []): Customer
{
    return Customer::factory()->create([
        'organization_id' => $organization->id,
        ...$attributes,
    ]);
}

it('returns a paginated list of customers with kaminari metadata', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    // 30 customers: two pages at the default limit of 25 (kaminari's
    // default_per_page, Lago does not override it).
    gqlMakeCustomer($organization, ['name' => 'Bulk']);
    for ($i = 0; $i < 29; $i++) {
        gqlMakeCustomer($organization, ['name' => "Customer {$i}"]);
    }

    $response = gqlPost(<<<'GQL'
    query {
        customers {
            collection { id externalId name }
            metadata { currentPage limitValue totalPages totalCount }
        }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    $metadata = $response->json('data.customers.metadata');

    expect($response->json('data.customers.collection'))->toHaveCount(25)
        ->and($metadata)->toBe([
            'currentPage' => 1,
            'limitValue' => 25,
            'totalPages' => 2,
            'totalCount' => 30,
        ]);
})->group('ledger:gql:query:customers');

it('honors page and limit arguments', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    gqlMakeCustomer($organization, ['name' => 'A']);
    gqlMakeCustomer($organization, ['name' => 'B']);
    gqlMakeCustomer($organization, ['name' => 'C']);

    $response = gqlPost(<<<'GQL'
    query {
        customers(page: 2, limit: 2) {
            collection { name }
            metadata { currentPage limitValue totalPages totalCount }
        }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.customers.collection'))->toHaveCount(1)
        ->and($response->json('data.customers.metadata'))->toBe([
            'currentPage' => 2,
            'limitValue' => 2,
            'totalPages' => 2,
            'totalCount' => 3,
        ]);
})->group('ledger:gql:query:customers');

it('searches across the searchable fields', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    gqlMakeCustomer($organization, ['name' => 'Acme Industries']);
    gqlMakeCustomer($organization, ['firstname' => 'Grace', 'lastname' => 'Hopper', 'name' => 'GH']);
    gqlMakeCustomer($organization, ['name' => 'Unrelated']);
    // Another organization's matching customer must not leak.
    gqlMakeCustomer(gqlCreateOrganization('Other Corp'), ['name' => 'Acme Industries']);

    $response = gqlPost(<<<'GQL'
    query {
        customers(searchTerm: "acme") {
            collection { name }
            metadata { totalCount }
        }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.customers.collection'))->toHaveCount(1)
        ->and($response->json('data.customers.metadata.totalCount'))->toBe(1)
        ->and($response->json('data.customers.collection.0.name'))->toBe('Acme Industries');
})->group('ledger:gql:query:customers');

it('filters by external id, disabling the search term', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    $customer = gqlMakeCustomer($organization, ['external_id' => 'ext-77', 'name' => 'Target']);
    gqlMakeCustomer($organization, ['name' => 'Other']);

    $response = gqlPost(<<<'GQL'
    query {
        customers(externalId: "ext-77", searchTerm: "nothing-matches") {
            collection { id externalId }
            metadata { totalCount }
        }
    }
    GQL, [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.customers.metadata.totalCount'))->toBe(1)
        ->and($response->json('data.customers.collection.0.id'))->toBe($customer->id);
})->group('ledger:gql:query:customers');

it('filters by customer type, tax identification number presence and deletion state', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    $company = gqlMakeCustomer($organization, ['customer_type' => 'company', 'tax_identification_number' => 'FR123456']);
    $deleted = gqlMakeCustomer($organization, ['name' => 'Deleted Inc']);

    $deleted->delete();

    $filter = static function (string $args) use ($user, $organization): array {
        $response = gqlPost(
            "query { customers({$args}) { collection { id } metadata { totalCount } } }",
            [],
            gqlAuthHeaders($user, $organization->id),
        );

        return [
            $response->json('data.customers.metadata.totalCount'),
            $response->json('data.customers.collection.*.id'),
        ];
    };

    expect($filter('customerType: company'))->toBe([1, [$company->id]])
        ->and($filter('hasTaxIdentificationNumber: true'))->toBe([1, [$company->id]])
        ->and($filter('withDeleted: false'))->toBe([1, [$company->id]])
        ->and(
            fn () => expect($filter('withDeleted: true')[0])->toBe(2)
                ->and(collect($filter('withDeleted: true')[1])->sort()->values()->all())->toBe(
                    collect([$company->id, $deleted->id])->sort()->values()->all(),
                ),
        );
})->group('ledger:gql:query:customers');

it('returns unauthorized on customers without a token', function (): void {
    $response = gqlPost('query { customers { metadata { totalCount } } }');

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:customers');

it('returns a single customer by id and by external id', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    $customer = gqlMakeCustomer($organization, ['external_id' => 'ext-1', 'currency' => 'EUR']);

    $byId = gqlPost(
        "query { customer(id: \"{$customer->id}\") { id externalId currency displayName } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($byId->json('data.customer.id'))->toBe($customer->id)
        ->and($byId->json('data.customer.externalId'))->toBe('ext-1')
        ->and($byId->json('data.customer.currency'))->toBe('EUR');

    $byExternalId = gqlPost(
        'query { customer(externalId: "ext-1") { id externalId } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($byExternalId->json('data.customer.id'))->toBe($customer->id);
})->group('ledger:gql:query:customer');

it('computes the display name like Rails', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    $legal = gqlMakeCustomer($organization, ['legal_name' => 'Legal Corp', 'name' => 'Nickname', 'firstname' => null, 'lastname' => null]);
    $person = gqlMakeCustomer($organization, ['legal_name' => null, 'name' => null, 'firstname' => 'Ada', 'lastname' => 'Lovelace']);

    $response = gqlPost(
        "query { c1: customer(id: \"{$legal->id}\") { displayName } c2: customer(id: \"{$person->id}\") { displayName } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.c1.displayName'))->toBe('Legal Corp')
        ->and($response->json('data.c2.displayName'))->toBe('Ada Lovelace');
})->group('ledger:gql:query:customer');

it('errors when neither id nor external id is given', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    $response = gqlPost('query { customer { id } }', [], gqlAuthHeaders($user, $organization->id));

    expect($response->json('errors.0.message'))->toBe('You must provide either `id` or `external_id`.');
})->group('ledger:gql:query:customer');

it('returns the not_found envelope for an unknown customer', function (): void {
    [$organization, $user] = gqlCustomersSetup();

    $response = gqlPost(
        'query { customer(externalId: "who-dis") { id } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.customer'))->toBeNull()
        ->and($response->json('errors.0.message'))->toBe('Resource not found')
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['customer' => ['not_found']],
        ]);
})->group('ledger:gql:query:customer');
