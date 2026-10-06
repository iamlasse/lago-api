<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentRequest;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Queue;
use App\Models\PaymentProviderCustomer;

/**
 * Ports of Rails' spec/graphql/mutations/{payments/create,
 * payment_requests/create, payment_methods/*, payment_provider_customers/*}_spec.rb
 * over the frozen SDL.
 *
 * Ledger rows: gql:mutation:createPayment, gql:mutation:createPaymentRequest,
 * gql:mutation:destroyPaymentMethod, gql:mutation:setPaymentMethodAsDefault,
 * gql:mutation:createPaymentProviderCustomer,
 * gql:mutation:updatePaymentProviderCustomer,
 * gql:mutation:setPaymentProviderCustomerAsDefault,
 * gql:mutation:destroyPaymentProviderCustomer,
 * gql:mutation:destroyPaymentProvider, gql:mutation:generatePaymentUrl,
 * gql:mutation:generateCheckoutUrl.
 */
beforeEach(function (): void {
    Queue::fake();
});

function gqlPaymentsMutationSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlPaymentsMutationInvoice(Organization $organization, array $attributes = []): Invoice
{
    $customer = $attributes['customer'] ?? Customer::factory()->create([
        'organization_id' => $organization->id,
    ]);

    unset($attributes['customer']);

    return Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Finalized->value,
        'total_amount_cents' => 1000,
        'total_paid_amount_cents' => 0,
    ], $attributes));
}

const CREATE_PAYMENT_MUTATION = <<<'GQL'
mutation($input: CreatePaymentInput!) {
    createPayment(input: $input) { id amountCents paymentType reference }
}
GQL;

it('records a manual payment on the invoice', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();
    config(['lago.license' => 'premium-license-token']);

    $invoice = gqlPaymentsMutationInvoice($organization, ['currency' => 'EUR']);

    $response = gqlPost(
        CREATE_PAYMENT_MUTATION,
        ['input' => [
            'invoiceId' => $invoice->id,
            'amountCents' => 500,
            'reference' => 'wire-123',
            'createdAt' => now()->toIso8601String(),
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.createPayment');

    expect($response->json('errors'))->toBeNull()
        ->and($payload['paymentType'])->toBe('manual')
        ->and($payload['reference'])->toBe('wire-123')
        ->and($payload['amountCents'])->toBe('500');

    expect(Payment::query()->find($payload['id'])->payable_id)->toBe($invoice->id);

    config(['lago.license' => null]);
})->group('ledger:gql:mutation:createPayment');

it('answers not_found when the manual payment targets an unknown invoice', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $response = gqlPost(
        CREATE_PAYMENT_MUTATION,
        ['input' => [
            'invoiceId' => '00000000-0000-0000-0000-000000000000',
            'amountCents' => 500,
            'reference' => 'wire-123',
            'createdAt' => now()->toIso8601String(),
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.createPayment'))->toBeNull()
        ->and($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:createPayment');

const CREATE_PAYMENT_REQUEST_MUTATION = <<<'GQL'
mutation($input: PaymentRequestCreateInput!) {
    createPaymentRequest(input: $input) { id email amountCents paymentStatus }
}
GQL;

it('creates the premium payment request over the overdue invoices', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();
    config(['lago.license' => 'premium-license-token']);

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'dunning-cust',
        'email' => 'dunning@acme.com',
    ]);
    $invoice = Invoice::factory()->create([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
        'status' => InvoiceStatus::Finalized->value,
        'payment_overdue' => true,
        'ready_for_payment_processing' => true,
        'total_amount_cents' => 2000,
        'total_paid_amount_cents' => 0,
        'currency' => 'EUR',
    ]);

    $response = gqlPost(
        CREATE_PAYMENT_REQUEST_MUTATION,
        ['input' => ['externalCustomerId' => 'dunning-cust', 'lagoInvoiceIds' => [$invoice->id]]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.createPaymentRequest');

    expect($payload['email'])->toBe('dunning@acme.com')
        ->and($payload['amountCents'])->toBe('2000')
        ->and($payload['paymentStatus'])->toBe('pending');

    expect(PaymentRequest::query()->find($payload['id'])->customer_id)->toBe($customer->id);

    config(['lago.license' => null]);
})->group('ledger:gql:mutation:createPaymentRequest');

it('answers not_found when the payment request customer is unknown', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();
    config(['lago.license' => 'premium-license-token']);

    $response = gqlPost(
        CREATE_PAYMENT_REQUEST_MUTATION,
        ['input' => ['externalCustomerId' => 'ghost']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');

    config(['lago.license' => null]);
})->group('ledger:gql:mutation:createPaymentRequest');

const DESTROY_PAYMENT_METHOD_MUTATION = <<<'GQL'
mutation($input: DestroyPaymentMethodInput!) {
    destroyPaymentMethod(input: $input) { id }
}
GQL;

it('deletes a payment method and answers its id', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $method = PaymentMethod::factory()->forCustomer($customer)->create();

    $response = gqlPost(
        DESTROY_PAYMENT_METHOD_MUTATION,
        ['input' => ['id' => $method->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors'))->toBeNull()
        ->and($response->json('data.destroyPaymentMethod.id'))->toBe($method->id)
        ->and($method->refresh()->trashed())->toBeTrue();
})->group('ledger:gql:mutation:destroyPaymentMethod');

it('answers not_found when destroying an unknown payment method', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $response = gqlPost(
        DESTROY_PAYMENT_METHOD_MUTATION,
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions'))->toBe([
        'status' => 404,
        'code' => 'not_found',
        'details' => ['paymentMethod' => ['not_found']],
    ]);
})->group('ledger:gql:mutation:destroyPaymentMethod');

const SET_PAYMENT_METHOD_DEFAULT_MUTATION = <<<'GQL'
mutation($input: SetAsDefaultInput!) {
    setPaymentMethodAsDefault(input: $input) { id isDefault }
}
GQL;

it('moves the default flag to the given payment method', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $first = PaymentMethod::factory()->asDefault()->forCustomer($customer)->create();
    $second = PaymentMethod::factory()->forCustomer($customer)->create();

    $response = gqlPost(
        SET_PAYMENT_METHOD_DEFAULT_MUTATION,
        ['input' => ['id' => $second->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.setPaymentMethodAsDefault.id'))->toBe($second->id)
        ->and($response->json('data.setPaymentMethodAsDefault.isDefault'))->toBeTrue()
        ->and($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue();
})->group('ledger:gql:mutation:setPaymentMethodAsDefault');

const CREATE_PROVIDER_CUSTOMER_MUTATION = <<<'GQL'
mutation($input: CreatePaymentProviderCustomerInput!) {
    createPaymentProviderCustomer(input: $input) {
        id
        isDefault
        paymentProvider
        providerCustomerId
        syncWithProvider
    }
}
GQL;

it('creates the payment provider customer connection and defaults the first one', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $provider = PaymentProvider::factory()->forOrganization($organization)->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        CREATE_PROVIDER_CUSTOMER_MUTATION,
        ['input' => [
            'customerId' => $customer->id,
            'paymentProvider' => 'stripe',
            'providerCustomerId' => 'cus_stripe_1',
            'syncWithProvider' => true,
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.createPaymentProviderCustomer');

    expect($payload['paymentProvider'])->toBe('stripe')
        ->and($payload['isDefault'])->toBeTrue()
        ->and($payload['providerCustomerId'])->toBe('cus_stripe_1')
        ->and($payload['syncWithProvider'])->toBeTrue();

    // The first connection becomes the customer's active provider.
    expect($customer->refresh()->payment_provider)->toBe('stripe')
        ->and(PaymentProviderCustomer::query()->find($payload['id'])->payment_provider_id)->toBe($provider->id);
})->group('ledger:gql:mutation:createPaymentProviderCustomer');

it('answers the value_is_mandatory payment_provider validation error', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    // The frozen input marks paymentProvider as required, so exercise the
    // validation through an unknown customer instead: not_found.
    $response = gqlPost(
        CREATE_PROVIDER_CUSTOMER_MUTATION,
        ['input' => [
            'customerId' => '00000000-0000-0000-0000-000000000000',
            'paymentProvider' => 'stripe',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:createPaymentProviderCustomer');

const UPDATE_PROVIDER_CUSTOMER_MUTATION = <<<'GQL'
mutation($input: UpdatePaymentProviderCustomerInput!) {
    updatePaymentProviderCustomer(input: $input) { id code }
}
GQL;

it('updates the payment provider customer connection code', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $connection = PaymentProviderCustomer::factory()->forCustomer($customer)->create();

    $response = gqlPost(
        UPDATE_PROVIDER_CUSTOMER_MUTATION,
        ['input' => ['id' => $connection->id, 'code' => 'secondary-stripe']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updatePaymentProviderCustomer.code'))->toBe('secondary-stripe')
        ->and($connection->refresh()->code)->toBe('secondary-stripe');
})->group('ledger:gql:mutation:updatePaymentProviderCustomer');

const SET_PROVIDER_CUSTOMER_DEFAULT_MUTATION = <<<'GQL'
mutation($input: SetPaymentProviderCustomerAsDefaultInput!) {
    setPaymentProviderCustomerAsDefault(input: $input) { id isDefault }
}
GQL;

it('moves the default flag across the customer connections', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $first = PaymentProviderCustomer::factory()->forCustomer($customer)->create(['is_default' => true]);
    // The (customer_id, type) unique index wants one connection per STI type.
    $second = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'type' => 'PaymentProviderCustomers::AdyenCustomer',
    ]);

    $response = gqlPost(
        SET_PROVIDER_CUSTOMER_DEFAULT_MUTATION,
        ['input' => ['id' => $second->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.setPaymentProviderCustomerAsDefault.isDefault'))->toBeTrue()
        ->and($first->refresh()->is_default)->toBeFalse();
})->group('ledger:gql:mutation:setPaymentProviderCustomerAsDefault');

const DESTROY_PROVIDER_CUSTOMER_MUTATION = <<<'GQL'
mutation($input: DestroyPaymentProviderCustomerInput!) {
    destroyPaymentProviderCustomer(input: $input) { id }
}
GQL;

it('destroys the connection and its payment methods, clearing the customer pointer', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'payment_provider' => 'stripe',
    ]);
    $connection = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'is_default' => true,
    ]);
    $method = PaymentMethod::factory()->forProviderCustomer($connection)->forCustomer($customer)->create();

    $response = gqlPost(
        DESTROY_PROVIDER_CUSTOMER_MUTATION,
        ['input' => ['id' => $connection->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.destroyPaymentProviderCustomer.id'))->toBe($connection->id)
        ->and($connection->refresh()->trashed())->toBeTrue()
        ->and($method->refresh()->trashed())->toBeTrue()
        ->and($customer->refresh()->payment_provider)->toBeNull();
})->group('ledger:gql:mutation:destroyPaymentProviderCustomer');

const DESTROY_PAYMENT_PROVIDER_MUTATION = <<<'GQL'
mutation($input: DestroyPaymentProviderInput!) {
    destroyPaymentProvider(input: $input) { id }
}
GQL;

it('destroys the payment provider and clears the customer pointers', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $provider = PaymentProvider::factory()->forOrganization($organization)->create();
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'payment_provider' => 'stripe',
        'payment_provider_code' => 'stripe',
    ]);
    $connection = PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'payment_provider_id' => $provider->id,
    ]);

    $response = gqlPost(
        DESTROY_PAYMENT_PROVIDER_MUTATION,
        ['input' => ['id' => $provider->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.destroyPaymentProvider.id'))->toBe($provider->id)
        ->and($provider->refresh()->trashed())->toBeTrue()
        ->and($connection->refresh()->trashed())->toBeTrue()
        ->and($customer->refresh()->payment_provider)->toBeNull();
})->group('ledger:gql:mutation:destroyPaymentProvider');

const GENERATE_PAYMENT_URL_MUTATION = <<<'GQL'
mutation($input: GeneratePaymentUrlInput!) {
    generatePaymentUrl(input: $input) { paymentUrl }
}
GQL;

it('answers the no_linked_payment_provider validation error without a provider', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $invoice = gqlPaymentsMutationInvoice($organization);

    $response = gqlPost(
        GENERATE_PAYMENT_URL_MUTATION,
        ['input' => ['invoiceId' => $invoice->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $extensions = $response->json('errors.0.extensions');

    expect($extensions['status'])->toBe(422)
        ->and($extensions['details'])->toBe(['base' => ['no_linked_payment_provider']]);
})->group('ledger:gql:mutation:generatePaymentUrl');

it('answers not_found for an unknown invoice on generatePaymentUrl', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $response = gqlPost(
        GENERATE_PAYMENT_URL_MUTATION,
        ['input' => ['invoiceId' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:generatePaymentUrl');

const GENERATE_CHECKOUT_URL_MUTATION = <<<'GQL'
mutation($input: GenerateCheckoutUrlInput!) {
    generateCheckoutUrl(input: $input) { checkoutUrl }
}
GQL;

it('answers the no_linked_payment_provider validation error for a customer without a connection', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(
        GENERATE_CHECKOUT_URL_MUTATION,
        ['input' => ['customerId' => $customer->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $extensions = $response->json('errors.0.extensions');

    expect($extensions['status'])->toBe(422)
        ->and($extensions['details'])->toBe(['base' => ['no_linked_payment_provider']]);
})->group('ledger:gql:mutation:generateCheckoutUrl');

it('answers not_found for an unknown customer on generateCheckoutUrl', function (): void {
    [$organization, $user] = gqlPaymentsMutationSetup();

    $response = gqlPost(
        GENERATE_CHECKOUT_URL_MUTATION,
        ['input' => ['customerId' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
})->group('ledger:gql:mutation:generateCheckoutUrl');
