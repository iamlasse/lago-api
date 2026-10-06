<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Plan;
use App\Models\Wallet;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use App\Support\Utils\PortalToken;
use App\Enums\InvoiceStatus;
use App\GraphQL\Guards\CustomerPortalUser as PortalGuard;

/**
 * Ports of the Rails customer-portal GraphQL specs
 * (spec/graphql/{concerns/authenticable_customer_portal_user_spec,
 * resolvers/customer_portal/*, mutations/customer_portal/*}) and the portal
 * services' specs (spec/services/customer_portal/*) over the frozen SDL.
 *
 * The portal context travels through the `customer-portal-token` request
 * header (Rails' CustomerPortalUser controller concern): the middleware
 * verifies the signed token and resolves context.customer_portal_user; the
 * CustomerPortalUser guard rejects every field without it.
 *
 * Ledger rows: gql:query:{customerPortalCustomerProjectedUsage,
 * customerPortalCustomerUsage,customerPortalInvoiceCollections,
 * customerPortalInvoices,customerPortalOrganization,
 * customerPortalOverdueBalances,customerPortalSubscription,
 * customerPortalSubscriptions,customerPortalUser,customerPortalWallet,
 * customerPortalWallets} gql:mutation:{createCustomerPortalWalletTransaction,
 * updateCustomerPortalCustomer,downloadCustomerPortalInvoice,
 * generateCustomerPortalUrl}.
 */
function gqlPortalSetup(string $email = 'portal@example.com'): array
{
    $organization = gqlCreateOrganization();
    BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser($email);
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlPortalCustomer(object $organization, array $attributes = []): Customer
{
    return Customer::factory()->create(array_merge([
        'organization_id' => $organization->id,
    ], $attributes));
}

/** Rails: `context: {customer_portal_user: customer}` — the token header. */
function gqlPortalHeaders(object $customer): array
{
    return ['customer-portal-token' => PortalToken::generate($customer->id)];
}

// -- the portal auth guard ---------------------------------------------------------

const PORTAL_USER_QUERY = <<<'GQL'
query {
    customerPortalUser {
        id
        name
        currency
        billingEntityBillingConfiguration { id documentLocale }
    }
}
GQL;

it('answers unauthorized on portal fields without a portal token', function (): void {
    [$organization] = gqlPortalSetup();
    gqlPortalCustomer($organization);

    $response = gqlPost(PORTAL_USER_QUERY, [], gqlAuthHeaders(gqlCreateUser('other@example.com'), $organization->id));

    $response->assertOk();

    $error = $response->json('errors.0');

    expect($error['message'])->toBe('unauthorized')
        ->and($error['extensions']['status'])->toBe('unauthorized')
        ->and($error['extensions']['code'])->toBe('unauthorized')
        ->and($response->json('data.customerPortalUser'))->toBeNull();
})->group('ledger:gql:query:customerPortalUser');

it('answers unauthorized on portal fields with an invalid portal token', function (): void {
    [$organization] = gqlPortalSetup();

    $response = gqlPost(
        PORTAL_USER_QUERY,
        [],
        ['customer-portal-token' => 'garbage--token'],
    );

    $response->assertOk();

    expect($response->json('errors.0.extensions.code'))->toBe('unauthorized');
});

it('answers unauthorized on portal fields with an expired portal token', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);

    // Rails: expires_in: 12.hours — mint a token, then move past the expiry.
    $expired = PortalToken::generate($customer->id);

    test()->travelTo(Carbon::now()->addHours(PortalToken::EXPIRES_IN_HOURS + 1));

    try {
        expect(PortalToken::verify($expired))->toBeNull();

        $response = gqlPost(PORTAL_USER_QUERY, [], ['customer-portal-token' => $expired]);

        $response->assertOk();

        expect($response->json('errors.0.extensions.code'))->toBe('unauthorized');
    } finally {
        test()->travelBack();
    }
});

it('answers unauthorized on portal fields with a token of an unknown customer', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $token = PortalToken::generate($customer->id);
    $customer->delete();

    $response = gqlPost(PORTAL_USER_QUERY, [], ['customer-portal-token' => $token]);

    $response->assertOk();

    expect($response->json('errors.0.extensions.code'))->toBe('unauthorized');
});

it('rejects a portal token signed with the wrong secret', function (): void {
    $token = PortalToken::generate('9f1aa9e0-0000-4000-8000-000000000099');

    // The token is HMAC'd over SECRET_KEY_BASE; a tampered payload or
    // digest fails to verify.
    [$data, $digest] = explode('--', $token);

    expect(PortalToken::verify($data.'--'.base64_encode('forged')))->toBeNull()
        ->and(PortalToken::verify($token.'x'))->toBeNull()
        ->and(PortalToken::verify(null))->toBeNull()
        ->and(PortalToken::verify(''))->toBeNull();
});

it('raises unauthorized from the CustomerPortalUser guard without a portal user', function (): void {
    $request = Illuminate\Http\Request::create('/graphql', 'POST');
    App\GraphQL\Support\LagoContext::set($request, null, null, null, null, null, null);

    try {
        PortalGuard::authorize(new Nuwave\Lighthouse\Execution\HttpGraphQLContext($request));
        $this->fail('Expected ExecutionError');
    } catch (App\GraphQL\Exceptions\ExecutionError $error) {
        expect($error->getMessage())->toBe('unauthorized')
            ->and($error->getExtensions())->toBe([
                'status' => 'unauthorized',
                'code' => 'unauthorized',
            ]);
    }
});

// -- mutation { generateCustomerPortalUrl } ----------------------------------------

it('generates the customer portal url from the admin api', function (): void {
    [$organization, $user] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);

    $response = gqlPost(
        'mutation($input: GenerateCustomerPortalUrlInput!) { generateCustomerPortalUrl(input: $input) { url } }',
        ['input' => ['id' => $customer->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $url = $response->json('data.generateCustomerPortalUrl.url');

    expect($url)->toContain('/customer-portal/');

    $message = explode('/customer-portal/', $url)[1];

    expect(PortalToken::verify($message))->toBe($customer->id);
})->group('ledger:gql:mutation:generateCustomerPortalUrl');

it('answers not_found on generateCustomerPortalUrl for an unknown customer', function (): void {
    [$organization, $user] = gqlPortalSetup();

    $response = gqlPost(
        'mutation($input: GenerateCustomerPortalUrlInput!) { generateCustomerPortalUrl(input: $input) { url } }',
        ['input' => ['id' => '00000000-0000-0000-0000-000000000000']],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $error = $response->json('errors.0');

    expect($error['message'])->toBe('Resource not found')
        ->and($error['extensions']['code'])->toBe('not_found');
})->group('ledger:gql:mutation:generateCustomerPortalUrl');

it('requires the current user on generateCustomerPortalUrl', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);

    $response = gqlPost(
        'mutation($input: GenerateCustomerPortalUrlInput!) { generateCustomerPortalUrl(input: $input) { url } }',
        ['input' => ['id' => $customer->id]],
        ['x-lago-organization' => $organization->id],
    );

    $response->assertOk();

    expect($response->json('errors.0.extensions.code'))->toBe('unauthorized');
})->group('ledger:gql:mutation:generateCustomerPortalUrl');

it('requires the organization header on generateCustomerPortalUrl', function (): void {
    [$organization, $user] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);

    $response = gqlPost(
        'mutation($input: GenerateCustomerPortalUrlInput!) { generateCustomerPortalUrl(input: $input) { url } }',
        ['input' => ['id' => $customer->id]],
        gqlAuthHeaders($user),
    );

    $response->assertOk();

    expect($response->json('errors.0.message'))->toBe('Missing organization id');
})->group('ledger:gql:mutation:generateCustomerPortalUrl');

// -- query { customerPortalUser } --------------------------------------------------

it('returns a single customer from the portal', function (): void {
    [$organization] = gqlPortalSetup();

    $customer = gqlPortalCustomer($organization, ['currency' => 'EUR']);

    $response = gqlPost(PORTAL_USER_QUERY, [], gqlPortalHeaders($customer));

    $response->assertOk();

    $payload = $response->json('data.customerPortalUser');

    expect($payload['id'])->toBe($customer->id)
        ->and($payload['currency'])->toBe('EUR')
        ->and($payload['billingEntityBillingConfiguration']['id'])->toBe($customer->billing_entity_id.'-c1nf')
        ->and($payload['billingEntityBillingConfiguration']['documentLocale'])
            ->toBe($customer->billingEntity->document_locale);
})->group('ledger:gql:query:customerPortalUser');

// -- query { customerPortalOrganization } ------------------------------------------

it('returns the customer portal organization', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);

    $response = gqlPost(
        <<<'GQL'
        query {
            customerPortalOrganization {
                id
                name
                billingConfiguration { id documentLocale }
                premiumIntegrations
            }
        }
        GQL,
        [],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.customerPortalOrganization');

    expect($payload['id'])->toBe($organization->id)
        ->and($payload['name'])->toBe($organization->name)
        ->and($payload['billingConfiguration']['id'])->toBe($organization->id.'-c0nf')
        ->and($payload['premiumIntegrations'])->toBe([]);
})->group('ledger:gql:query:customerPortalOrganization');

// -- query { customerPortalInvoices } ----------------------------------------------

function gqlPortalInvoice(object $organization, Customer $customer, array $attributes = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'customer_id' => $customer->id,
    ], $attributes));
}

it('returns the customer invoices from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $draft = gqlPortalInvoice($organization, $customer, ['status' => InvoiceStatus::Draft->value]);
    $finalized = gqlPortalInvoice($organization, $customer);

    $response = gqlPost(
        <<<'GQL'
        query {
            customerPortalInvoices(limit: 5) {
                collection { id }
                metadata { currentPage totalCount }
            }
        }
        GQL,
        [],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.customerPortalInvoices');

    expect($payload['collection'])->toHaveCount(2)
        ->and(collect($payload['collection'])->pluck('id')->sort()->values()->all())
            ->toBe(collect([$draft->id, $finalized->id])->sort()->values()->all())
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(2);
})->group('ledger:gql:query:customerPortalInvoices');

it('filters the customer portal invoices by status', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $draft = gqlPortalInvoice($organization, $customer, ['status' => InvoiceStatus::Draft->value]);
    gqlPortalInvoice($organization, $customer);

    $response = gqlPost(
        <<<'GQL'
        query($status: [InvoiceStatusTypeEnum!]) {
            customerPortalInvoices(status: $status) {
                collection { id }
                metadata { totalCount }
            }
        }
        GQL,
        ['status' => ['draft']],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.customerPortalInvoices');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($draft->id)
        ->and($payload['metadata']['totalCount'])->toBe(1);
})->group('ledger:gql:query:customerPortalInvoices');

it('never returns another customer invoices on the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $other = gqlPortalCustomer($organization);
    gqlPortalInvoice($organization, $other);

    $response = gqlPost(
        'query { customerPortalInvoices(limit: 5) { collection { id } metadata { totalCount } } }',
        [],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    expect($response->json('data.customerPortalInvoices.collection'))->toHaveCount(0)
        ->and($response->json('data.customerPortalInvoices.metadata.totalCount'))->toBe(0);
})->group('ledger:gql:query:customerPortalInvoices');

// -- query { customerPortalSubscription } / { customerPortalSubscriptions } --------

function gqlPortalSubscription(object $organization, Customer $customer, array $attributes = []): Subscription
{
    $plan = $attributes['plan'] ?? Plan::factory()->create(['organization_id' => $organization->id]);
    unset($attributes['plan']);

    return Subscription::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'customer_id' => $customer->id,
        'status' => App\Enums\SubscriptionStatus::Active->value,
    ], $attributes));
}

it('returns a single subscription from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $subscription = gqlPortalSubscription($organization, $customer);

    $response = gqlPost(
        <<<'GQL'
        query($id: ID!) {
            customerPortalSubscription(id: $id) {
                id
                externalId
                plan { code }
            }
        }
        GQL,
        ['id' => $subscription->id],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.customerPortalSubscription');

    expect($payload['id'])->toBe($subscription->id)
        ->and($payload['externalId'])->toBe($subscription->external_id)
        ->and($payload['plan']['code'])->toBe($subscription->plan->code);
})->group('ledger:gql:query:customerPortalSubscription');

it('answers not_found for an unknown or foreign subscription on the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $other = gqlPortalCustomer($organization);
    $foreign = gqlPortalSubscription($organization, $other);

    foreach (['foo', $foreign->id] as $id) {
        $response = gqlPost(
            'query($id: ID!) { customerPortalSubscription(id: $id) { id } }',
            ['id' => $id],
            gqlPortalHeaders($customer),
        );

        $response->assertOk();

        expect($response->json('errors.0.message'))->toBe('Resource not found')
            ->and($response->json('errors.0.extensions.code'))->toBe('not_found');
    }
})->group('ledger:gql:query:customerPortalSubscription');

it('returns the customer subscriptions from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $plan = Plan::factory()->create(['organization_id' => $organization->id]);
    $active = gqlPortalSubscription($organization, $customer, ['plan' => $plan]);
    $terminated = gqlPortalSubscription($organization, $customer, [
        'plan' => $plan,
        'status' => App\Enums\SubscriptionStatus::Terminated->value,
        'terminated_at' => now(),
    ]);

    $query = <<<'GQL'
    query($planCode: String, $status: [StatusTypeEnum!], $currency: String) {
        customerPortalSubscriptions(limit: 5, planCode: $planCode, status: $status, currency: $currency) {
            collection { id }
            metadata { currentPage totalCount }
        }
    }
    GQL;

    $response = gqlPost($query, ['planCode' => $plan->code, 'status' => ['active'], 'currency' => null], gqlPortalHeaders($customer));

    $response->assertOk();

    $payload = $response->json('data.customerPortalSubscriptions');

    expect(collect($payload['collection'])->pluck('id')->all())->toBe([$active->id])
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(1);

    $response = gqlPost($query, ['planCode' => null, 'status' => ['terminated'], 'currency' => null], gqlPortalHeaders($customer));

    $response->assertOk();

    expect($response->json('data.customerPortalSubscriptions.collection.0.id'))->toBe($terminated->id);
})->group('ledger:gql:query:customerPortalSubscriptions');

// -- query { customerPortalWallet } / { customerPortalWallets } --------------------

function gqlPortalWallet(object $organization, Customer $customer, array $attributes = []): Wallet
{
    return Wallet::factory()->forCustomer($customer)->create($attributes);
}

it('returns a single wallet from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $wallet = gqlPortalWallet($organization, $customer, ['name' => 'Portal Wallet']);

    $response = gqlPost(
        <<<'GQL'
        query($id: ID!) {
            customerPortalWallet(id: $id) { id name priority currency status }
        }
        GQL,
        ['id' => $wallet->id],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.customerPortalWallet');

    expect($payload['id'])->toBe($wallet->id)
        ->and($payload['name'])->toBe('Portal Wallet')
        ->and($payload['status'])->toBe('active');
})->group('ledger:gql:query:customerPortalWallet');

it('answers not_found for an unknown or foreign wallet on the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $other = gqlPortalCustomer($organization);
    $foreign = gqlPortalWallet($organization, $other);

    foreach (['foo', $foreign->id] as $id) {
        $response = gqlPost(
            'query($id: ID!) { customerPortalWallet(id: $id) { id } }',
            ['id' => $id],
            gqlPortalHeaders($customer),
        );

        $response->assertOk();

        expect($response->json('errors.0.message'))->toBe('Resource not found')
            ->and($response->json('errors.0.extensions.code'))->toBe('not_found');
    }
})->group('ledger:gql:query:customerPortalWallet');

it('returns the customer wallets from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $active = gqlPortalWallet($organization, $customer, ['paid_top_up_min_amount_cents' => 1000]);
    $terminated = gqlPortalWallet($organization, $customer);
    $terminated->status = App\Enums\WalletStatus::Terminated;
    $terminated->terminated_at = now();
    $terminated->save();

    $query = <<<'GQL'
    query($status: WalletStatusEnum) {
        customerPortalWallets(limit: 10, status: $status) {
            collection { id status paidTopUpMinAmountCents }
            metadata { currentPage totalCount }
        }
    }
    GQL;

    // Without a status filter only the ACTIVE wallets are listed.
    $response = gqlPost($query, ['status' => null], gqlPortalHeaders($customer));

    $response->assertOk();

    $payload = $response->json('data.customerPortalWallets');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($active->id)
        ->and($payload['collection'][0]['status'])->toBe('active')
        ->and($payload['collection'][0]['paidTopUpMinAmountCents'])->toBe('1000')
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(1);

    $response = gqlPost($query, ['status' => 'terminated'], gqlPortalHeaders($customer));

    $response->assertOk();

    expect($response->json('data.customerPortalWallets.collection.0.id'))->toBe($terminated->id);
})->group('ledger:gql:query:customerPortalWallets');

// -- query { customerPortalCustomerUsage } / { customerPortalCustomerProjectedUsage }

it('answers the customer portal usage queries', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $plan = Plan::factory()->create(['organization_id' => $organization->id, 'amount_cents' => 0]);
    $subscription = gqlPortalSubscription($organization, $customer, ['plan' => $plan]);

    $query = <<<'GQL'
    query($subscriptionId: ID!) {
        customerPortalCustomerUsage(subscriptionId: $subscriptionId) { amountCents currency }
        customerPortalCustomerProjectedUsage(subscriptionId: $subscriptionId) { amountCents currency }
    }
    GQL;

    $response = gqlPost($query, ['subscriptionId' => $subscription->id], gqlPortalHeaders($customer));

    $response->assertOk();

    expect($response->json('errors'))->toBeNull()
        ->and($response->json('data.customerPortalCustomerUsage.amountCents'))->toBe('0')
        ->and($response->json('data.customerPortalCustomerProjectedUsage.amountCents'))->toBe('0');
})->group('ledger:gql:query:customerPortalCustomerUsage', 'ledger:gql:query:customerPortalCustomerProjectedUsage');

it('answers not_found on the portal usage queries for an unknown subscription', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);

    foreach (['customerPortalCustomerUsage', 'customerPortalCustomerProjectedUsage'] as $field) {
        $response = gqlPost(
            "query(\$subscriptionId: ID!) { {$field}(subscriptionId: \$subscriptionId) { amountCents } }",
            ['subscriptionId' => '00000000-0000-0000-0000-000000000000'],
            gqlPortalHeaders($customer),
        );

        $response->assertOk();

        expect($response->json('errors.0.message'))->toBe('Resource not found')
            ->and($response->json('errors.0.extensions.code'))->toBe('not_found')
            ->and($response->json('errors.0.extensions.details'))->toBe(['customer' => ['not_found']]);
    }
})->group('ledger:gql:query:customerPortalCustomerUsage', 'ledger:gql:query:customerPortalCustomerProjectedUsage');

// -- query { customerPortalInvoiceCollections } / { customerPortalOverdueBalances } -

it('answers the customer portal analytics queries', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization, ['currency' => 'EUR']);

    $response = gqlPost(
        <<<'GQL'
        query($expireCache: Boolean) {
            customerPortalInvoiceCollections(expireCache: $expireCache) {
                collection { month amountCents currency invoicesCount }
                metadata { totalCount }
            }
            customerPortalOverdueBalances(expireCache: $expireCache) {
                collection { month amountCents currency }
                metadata { totalCount }
            }
        }
        GQL,
        ['expireCache' => true],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    expect($response->json('data.customerPortalInvoiceCollections.metadata.totalCount'))->toBe(0)
        ->and($response->json('data.customerPortalInvoiceCollections.collection'))->toBe([])
        ->and($response->json('data.customerPortalOverdueBalances.metadata.totalCount'))->toBe(0);
})->group('ledger:gql:query:customerPortalInvoiceCollections', 'ledger:gql:query:customerPortalOverdueBalances');

// -- mutation { updateCustomerPortalCustomer } -------------------------------------

it('updates the portal customer', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization, ['legal_name' => null]);

    $response = gqlPost(
        <<<'GQL'
        mutation($input: UpdateCustomerPortalCustomerInput!) {
            updateCustomerPortalCustomer(input: $input) {
                id
                customerType
                name
                firstname
                lastname
                legalName
                taxIdentificationNumber
                email
                addressLine1
                zipcode
                city
                state
                country
                billingConfiguration { documentLocale }
                billingEntityBillingConfiguration { documentLocale }
                shippingAddress { addressLine1 zipcode city country }
            }
        }
        GQL,
        ['input' => [
            'customerType' => 'company',
            'name' => 'Updated customer name',
            'firstname' => 'Updated customer firstname',
            'lastname' => 'Updated customer lastname',
            'legalName' => 'Updated customer legalName',
            'taxIdentificationNumber' => '2246',
            'email' => 'customer@email.test',
            'documentLocale' => 'fr',
            'addressLine1' => 'Updated customer addressLine1',
            'zipcode' => 'Updated customer zipcode',
            'city' => 'Updated customer city',
            'state' => 'Updated customer state',
            'country' => 'PT',
            'shippingAddress' => [
                'addressLine1' => 'Updated customer shipping addressLine1',
                'zipcode' => 'Updated customer shipping zipcode',
                'city' => 'Updated customer shipping city',
                'country' => 'ES',
            ],
        ]],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.updateCustomerPortalCustomer');

    expect($payload['id'])->toBe($customer->id)
        ->and($payload['customerType'])->toBe('company')
        ->and($payload['name'])->toBe('Updated customer name')
        ->and($payload['firstname'])->toBe('Updated customer firstname')
        ->and($payload['lastname'])->toBe('Updated customer lastname')
        ->and($payload['legalName'])->toBe('Updated customer legalName')
        ->and($payload['taxIdentificationNumber'])->toBe('2246')
        ->and($payload['email'])->toBe('customer@email.test')
        ->and($payload['addressLine1'])->toBe('Updated customer addressLine1')
        ->and($payload['zipcode'])->toBe('Updated customer zipcode')
        ->and($payload['city'])->toBe('Updated customer city')
        ->and($payload['state'])->toBe('Updated customer state')
        ->and($payload['country'])->toBe('PT')
        ->and($payload['billingConfiguration']['documentLocale'])->toBe('fr')
        ->and($payload['billingEntityBillingConfiguration']['documentLocale'])
            ->toBe($customer->billingEntity->document_locale)
        ->and($payload['shippingAddress']['addressLine1'])->toBe('Updated customer shipping addressLine1')
        ->and($payload['shippingAddress']['zipcode'])->toBe('Updated customer shipping zipcode')
        ->and($payload['shippingAddress']['city'])->toBe('Updated customer shipping city')
        ->and($payload['shippingAddress']['country'])->toBe('ES');
})->group('ledger:gql:mutation:updateCustomerPortalCustomer');

it('keeps the fields that were not sent on updateCustomerPortalCustomer', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization, ['firstname' => 'Old firstname']);

    $response = gqlPost(
        'mutation($input: UpdateCustomerPortalCustomerInput!) { updateCustomerPortalCustomer(input: $input) { id name firstname } }',
        ['input' => ['name' => 'Updated customer name']],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.updateCustomerPortalCustomer');

    expect($payload['name'])->toBe('Updated customer name')
        ->and($payload['firstname'])->toBe('Old firstname')
        ->and($customer->refresh()->currency)->toBe($customer->currency);
})->group('ledger:gql:mutation:updateCustomerPortalCustomer');

// -- mutation { downloadCustomerPortalInvoice } ------------------------------------

it('downloads the portal invoice PDF', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);

    // LAGO_DISABLE_PDF_GENERATION short-circuits the Gotenberg call, exactly
    // like Rails — the mutation answers the invoice.
    config(['lago.disable_pdf_generation' => true]);

    $invoice = gqlPortalInvoice($organization, $customer, ['status' => InvoiceStatus::Finalized->value]);

    $response = gqlPost(
        'mutation($input: DownloadCustomerPortalInvoiceInput!) { downloadCustomerPortalInvoice(input: $input) { id } }',
        ['input' => ['id' => $invoice->id]],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    expect($response->json('data.downloadCustomerPortalInvoice.id'))->toBe($invoice->id);

    config(['lago.disable_pdf_generation' => false]);
})->group('ledger:gql:mutation:downloadCustomerPortalInvoice');

it('answers not_found when downloading a foreign or invisible invoice from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $other = gqlPortalCustomer($organization);
    $foreign = gqlPortalInvoice($organization, $other);
    $closed = gqlPortalInvoice($organization, $customer, ['status' => App\Enums\InvoiceStatus::Closed->value]);

    foreach ([$foreign->id, $closed->id] as $id) {
        $response = gqlPost(
            'mutation($input: DownloadCustomerPortalInvoiceInput!) { downloadCustomerPortalInvoice(input: $input) { id } }',
            ['input' => ['id' => $id]],
            gqlPortalHeaders($customer),
        );

        $response->assertOk();

        expect($response->json('errors.0.extensions.code'))->toBe('not_found');
    }
})->group('ledger:gql:mutation:downloadCustomerPortalInvoice');

// -- mutation { createCustomerPortalWalletTransaction } ----------------------------

it('creates a wallet transaction from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $wallet = gqlPortalWallet($organization, $customer, [
        'credits_balance' => 10.0,
    ]);

    $response = gqlPost(
        <<<'GQL'
        mutation($input: CreateCustomerPortalWalletTransactionInput!) {
            createCustomerPortalWalletTransaction(input: $input) {
                collection { id status amount transactionStatus }
                metadata { totalCount }
            }
        }
        GQL,
        ['input' => ['walletId' => $wallet->id, 'paidCredits' => '5.00']],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    $payload = $response->json('data.createCustomerPortalWalletTransaction');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['status'])->toBe('pending')
        ->and($payload['collection'][0]['transactionStatus'])->toBe('purchased')
        ->and($payload['collection'][0]['amount'])->toBe('5.0')
        ->and($payload['metadata']['totalCount'])->toBe(1);

    expect(App\Models\WalletTransaction::query()->where('wallet_id', $wallet->id)->count())->toBe(1);
})->group('ledger:gql:mutation:createCustomerPortalWalletTransaction');

it('answers unprocessable when the wallet has a minimum top up amount', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $wallet = gqlPortalWallet($organization, $customer, [
        'credits_balance' => 10.0,
        'paid_top_up_min_amount_cents' => 1000,
    ]);

    $response = gqlPost(
        'mutation($input: CreateCustomerPortalWalletTransactionInput!) { createCustomerPortalWalletTransaction(input: $input) { collection { id } } }',
        ['input' => ['walletId' => $wallet->id, 'paidCredits' => '5.123459999']],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    expect($response->json('errors.0.message'))->toBe('Unprocessable Entity');
})->group('ledger:gql:mutation:createCustomerPortalWalletTransaction');

it('answers unprocessable when topping up another customer wallet from the portal', function (): void {
    [$organization] = gqlPortalSetup();
    $customer = gqlPortalCustomer($organization);
    $other = gqlPortalCustomer($organization);
    $foreignWallet = gqlPortalWallet($organization, $other, [
        'credits_balance' => 10.0,
    ]);

    $response = gqlPost(
        'mutation($input: CreateCustomerPortalWalletTransactionInput!) { createCustomerPortalWalletTransaction(input: $input) { collection { id } } }',
        ['input' => ['walletId' => $foreignWallet->id, 'paidCredits' => '5.00']],
        gqlPortalHeaders($customer),
    );

    $response->assertOk();

    expect($response->json('errors.0.message'))->toBe('Unprocessable Entity')
        ->and(App\Models\WalletTransaction::query()->where('wallet_id', $foreignWallet->id)->count())->toBe(0);
})->group('ledger:gql:mutation:createCustomerPortalWalletTransaction');
