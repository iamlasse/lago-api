<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';
require_once __DIR__.'/InvoicesResolverTest.php';

use App\Models\Customer;
use App\Enums\InvoiceStatus;

/**
 * Ports of Rails' spec/graphql/resolvers/customers/invoices_resolver_spec.rb
 * over the frozen SDL.
 *
 * Ledger rows: gql:query:customerInvoices.
 */
const CUSTOMER_INVOICES_QUERY = <<<'GQL'
query($customerId: ID!) {
    customerInvoices(customerId: $customerId, limit: 5) {
        collection { id status currency }
        metadata { currentPage totalCount }
    }
}
GQL;

it('lists the invoices of the customer', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $first = gqlMakeInvoice($organization, ['customer' => $customer]);
    $second = gqlMakeInvoice($organization, ['customer' => $customer, 'currency' => 'USD']);
    gqlMakeInvoice($organization);

    $response = gqlPost(
        CUSTOMER_INVOICES_QUERY,
        ['customerId' => $customer->id],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.customerInvoices');

    expect($payload['metadata']['totalCount'])->toBe(2)
        ->and(collect($payload['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id]);
})->group('ledger:gql:query:customerInvoices');

it('filters the customer invoices by status and currency', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $draft = gqlMakeInvoice($organization, ['customer' => $customer, 'status' => InvoiceStatus::Draft->value]);
    gqlMakeInvoice($organization, ['customer' => $customer]);
    $usd = gqlMakeInvoice($organization, ['customer' => $customer, 'currency' => 'USD']);

    $draftOnly = gqlPost(
        'query($customerId: ID!) { customerInvoices(customerId: $customerId, status: [draft]) { collection { id } metadata { totalCount } } }',
        ['customerId' => $customer->id],
        gqlAuthHeaders($user, $organization->id),
    );
    $usdOnly = gqlPost(
        'query($customerId: ID!) { customerInvoices(customerId: $customerId, currency: USD) { collection { id } metadata { totalCount } } }',
        ['customerId' => $customer->id],
        gqlAuthHeaders($user, $organization->id),
    );

    expect(collect($draftOnly->json('data.customerInvoices.collection'))->pluck('id')->all())->toBe([$draft->id])
        ->and(collect($usdOnly->json('data.customerInvoices.collection'))->pluck('id')->all())->toBe([$usd->id]);
})->group('ledger:gql:query:customerInvoices');

it('answers the customer not_found envelope for an unknown customer', function (): void {
    [$organization, $user] = gqlInvoicesSetup();

    $response = gqlPost(
        CUSTOMER_INVOICES_QUERY,
        ['customerId' => '00000000-0000-0000-0000-000000000000'],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.customerInvoices'))->toBeNull()
        ->and($response->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['customer' => ['not_found']],
        ]);
})->group('ledger:gql:query:customerInvoices');

it('answers unauthorized on customerInvoices without a token', function (): void {
    $response = gqlPost(CUSTOMER_INVOICES_QUERY, ['customerId' => 'anything']);

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:customerInvoices');
