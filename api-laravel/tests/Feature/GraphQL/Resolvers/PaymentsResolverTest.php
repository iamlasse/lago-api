<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Payment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentRequest;
use App\Models\PaymentProvider;

/**
 * Ports of Rails' spec/graphql/resolvers/{payments_resolver,
 * payment_methods_resolver, payment_provider_resolver,
 * payment_providers_resolver, payment_requests_resolver}_spec.rb over the
 * frozen SDL.
 *
 * Ledger rows: gql:query:payments, gql:query:paymentMethods,
 * gql:query:paymentProvider, gql:query:paymentProviders,
 * gql:query:paymentRequests.
 */
function gqlPaymentsSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlPaymentsCustomer(Organization $organization, array $attributes = []): Customer
{
    return Customer::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $attributes));
}

const PAYMENTS_LIST_QUERY = <<<'GQL'
query {
    payments(limit: 5) {
        collection { id amountCents amountCurrency paymentType payablePaymentStatus payable { __typename } }
        metadata { currentPage totalCount }
    }
}
GQL;

it('lists the organization payments with the payable union', function (): void {
    [$organization, $user] = gqlPaymentsSetup();

    $customer = gqlPaymentsCustomer($organization);
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();
    $payment = Payment::factory()->forInvoice($invoice)->create();

    $response = gqlPost(PAYMENTS_LIST_QUERY, [], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.payments');

    expect($response->json('errors'))->toBeNull()
        ->and($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($payment->id)
        ->and($payload['collection'][0]['amountCents'])->toBe((string) $payment->amount_cents)
        ->and($payload['collection'][0]['payable']['__typename'])->toBe('Invoice');
})->group('ledger:gql:query:payments');

it('filters payments by currency, external customer id and invoice id', function (): void {
    [$organization, $user] = gqlPaymentsSetup();

    $customer = gqlPaymentsCustomer($organization, ['external_id' => 'cust-ext']);
    $otherCustomer = gqlPaymentsCustomer($organization);
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();
    $eurPayment = Payment::factory()->forInvoice($invoice)->create(['amount_currency' => 'EUR']);
    $usdPayment = Payment::factory()->forInvoice($invoice)->create(['amount_currency' => 'USD']);
    $otherPayment = Payment::factory()->forInvoice($invoice)->forCustomer($otherCustomer)->create();

    $list = static fn (string $args): array => gqlPost(
        "query { payments(limit: 5, {$args}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.payments');

    expect(collect($list('currency: EUR')['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$eurPayment->id, $otherPayment->id])
        ->and(collect($list('currency: USD')['collection'])->pluck('id')->all())->toBe([$usdPayment->id])
        ->and(collect($list('externalCustomerId: "cust-ext"')['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$eurPayment->id, $usdPayment->id])
        ->and(collect($list('invoiceId: "'.$invoice->id.'"')['collection'])->pluck('id')->all())
        ->toEqualCanonicalizing([$eurPayment->id, $usdPayment->id, $otherPayment->id]);
})->group('ledger:gql:query:payments');

it('answers unauthorized on payments without a token', function (): void {
    $response = gqlPost(PAYMENTS_LIST_QUERY);

    expect($response->json('errors.0.message'))->toBe('unauthorized')
        ->and($response->json('errors.0.extensions.status'))->toBe('unauthorized');
})->group('ledger:gql:query:payments');

const PAYMENT_METHODS_QUERY = <<<'GQL'
query($externalCustomerId: ID!) {
    paymentMethods(externalCustomerId: $externalCustomerId, limit: 5) {
        collection { id isDefault paymentProviderType details { type brand last4 } }
        metadata { currentPage totalCount }
    }
}
GQL;

it('lists the payment methods of the external customer', function (): void {
    [$organization, $user] = gqlPaymentsSetup();

    $customer = gqlPaymentsCustomer($organization, ['external_id' => 'cust-methods']);
    $method = PaymentMethod::factory()->asDefault()->forCustomer($customer)->create();

    $response = gqlPost(
        PAYMENT_METHODS_QUERY,
        ['externalCustomerId' => 'cust-methods'],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.paymentMethods');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($method->id)
        ->and($payload['collection'][0]['isDefault'])->toBeTrue()
        ->and($payload['collection'][0]['details']['type'])->toBe('card')
        ->and($payload['collection'][0]['details']['last4'])->toBe('4242');
})->group('ledger:gql:query:paymentMethods');

it('keeps deleted payment methods out of the default scope and honors withDeleted', function (): void {
    [$organization, $user] = gqlPaymentsSetup();

    $customer = gqlPaymentsCustomer($organization, ['external_id' => 'cust-del']);
    $deleted = PaymentMethod::factory()->forCustomer($customer)->create();
    $deleted->delete();

    $list = static fn (string $extra): array => gqlPost(
        "query { paymentMethods(externalCustomerId: \"cust-del\", {$extra}) { collection { id } metadata { totalCount } } }",
        [],
        gqlAuthHeaders($user, $organization->id),
    )->json('data.paymentMethods');

    expect($list('limit: 5')['metadata']['totalCount'])->toBe(0)
        ->and($list('limit: 5, withDeleted: true')['metadata']['totalCount'])->toBe(1);
})->group('ledger:gql:query:paymentMethods');

it('answers a single payment provider by id and by code with the not_found envelope on a miss', function (): void {
    [$organization, $user] = gqlPaymentsSetup();

    $provider = PaymentProvider::factory()->forOrganization($organization)->create();

    $byId = gqlPost(
        'query($id: ID) { paymentProvider(id: $id) { ... on StripeProvider { id code } } }',
        ['id' => $provider->id],
        gqlAuthHeaders($user, $organization->id),
    );
    $byCode = gqlPost(
        'query { paymentProvider(code: "'.$provider->code.'") { ... on StripeProvider { id code name } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );
    $miss = gqlPost(
        'query($id: ID) { paymentProvider(id: $id) { __typename } }',
        ['id' => '00000000-0000-0000-0000-000000000000'],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($byId->json('data.paymentProvider.id'))->toBe($provider->id)
        ->and($byCode->json('data.paymentProvider.code'))->toBe('stripe')
        ->and($byCode->json('data.paymentProvider.name'))->toBe('Stripe')
        ->and($miss->json('data.paymentProvider'))->toBeNull()
        // The error envelope lower-camelizes the details keys (Errors::executionError).
        ->and($miss->json('errors.0.extensions'))->toBe([
            'status' => 404,
            'code' => 'not_found',
            'details' => ['paymentProvider' => ['not_found']],
        ]);
})->group('ledger:gql:query:paymentProvider');

it('lists the organization payment providers with the type filter', function (): void {
    [$organization, $user] = gqlPaymentsSetup();

    $stripe = PaymentProvider::factory()->forOrganization($organization)->create();
    $other = Organization::factory()->create();
    PaymentProvider::factory()->forOrganization($other)->create();

    $response = gqlPost(
        'query { paymentProviders(limit: 5) { collection { __typename ... on StripeProvider { id code } } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );
    $filtered = gqlPost(
        'query { paymentProviders(type: stripe) { collection { __typename } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );
    $emptyType = gqlPost(
        'query { paymentProviders(type: adyen) { collection { __typename } metadata { totalCount } } }',
        [],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.paymentProviders.collection.0.__typename'))->toBe('StripeProvider')
        ->and($response->json('data.paymentProviders.metadata.totalCount'))->toBe(1)
        ->and($filtered->json('data.paymentProviders.collection.0.__typename'))->toBe('StripeProvider')
        ->and($emptyType->json('data.paymentProviders.metadata.totalCount'))->toBe(0);
})->group('ledger:gql:query:paymentProviders');

const PAYMENT_REQUESTS_QUERY = <<<'GQL'
query {
    paymentRequests(limit: 5) {
        collection {
            id
            amountCents
            email
            paymentStatus
            payableType
            invoices { id }
        }
        metadata { totalCount }
    }
}
GQL;

it('lists the organization payment requests', function (): void {
    [$organization, $user] = gqlPaymentsSetup();

    $customer = gqlPaymentsCustomer($organization, ['email' => 'requests@acme.com']);
    $invoice = App\Models\Invoice::factory()->for($customer, 'customer')->for($organization, 'organization')->create();
    $request = PaymentRequest::factory()->forCustomer($customer)->create([
        'organization_id' => $organization->id,
        'email' => 'requests@acme.com',
    ]);
    $request->appliedInvoices()->create([
        'payment_request_id' => $request->id,
        'invoice_id' => $invoice->id,
        'organization_id' => $organization->id,
    ]);

    $response = gqlPost(PAYMENT_REQUESTS_QUERY, [], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.paymentRequests');

    expect($payload['metadata']['totalCount'])->toBe(1)
        ->and($payload['collection'][0]['id'])->toBe($request->id)
        ->and($payload['collection'][0]['payableType'])->toBe('PaymentRequest')
        ->and($payload['collection'][0]['paymentStatus'])->toBe('pending')
        ->and($payload['collection'][0]['invoices'])->toBe([['id' => $invoice->id]]);
})->group('ledger:gql:query:paymentRequests');
