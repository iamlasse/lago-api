<?php

declare(strict_types=1);

require_once __DIR__.'/../GraphQLHelpers.php';
require_once __DIR__.'/../AuthPlumbingTest.php';

use App\Models\Customer;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of Rails' spec/graphql/mutations/payment_providers/*_spec.rb (the
 * add/update provider variants) over the frozen SDL.
 *
 * Ledger rows: gql:mutation:addStripePaymentProvider,
 * gql:mutation:updateStripePaymentProvider,
 * gql:mutation:addAdyenPaymentProvider, gql:mutation:updateAdyenPaymentProvider,
 * gql:mutation:addCashfreePaymentProvider,
 * gql:mutation:updateCashfreePaymentProvider,
 * gql:mutation:addFlutterwavePaymentProvider,
 * gql:mutation:updateFlutterwavePaymentProvider,
 * gql:mutation:addGocardlessPaymentProvider,
 * gql:mutation:updateGocardlessPaymentProvider,
 * gql:mutation:addMoneyhashPaymentProvider,
 * gql:mutation:updateMoneyhashPaymentProvider.
 */
beforeEach(function (): void {
    Queue::fake();
});

function gqlPaymentProvidersSetup(): array
{
    $organization = gqlCreateOrganization();
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

const ADD_STRIPE_MUTATION = <<<'GQL'
mutation($input: AddStripePaymentProviderInput!) {
    addStripePaymentProvider(input: $input) {
        ... on StripeProvider { id code name supports3ds requireTermsOfServiceConsent secretKey }
    }
}
GQL;

const UPDATE_STRIPE_MUTATION = <<<'GQL'
mutation($input: UpdateStripePaymentProviderInput!) {
    updateStripePaymentProvider(input: $input) {
        ... on StripeProvider { id code name supports3ds }
    }
}
GQL;

it('adds the stripe payment provider and provisions the webhook', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $response = gqlPost(
        ADD_STRIPE_MUTATION,
        ['input' => [
            'code' => 'stripe-eu',
            'name' => 'Stripe EU',
            'secretKey' => 'sk_test_123456789abcdef',
            'supports3ds' => true,
            'requireTermsOfServiceConsent' => true,
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.addStripePaymentProvider');

    expect($payload['code'])->toBe('stripe-eu')
        ->and($payload['name'])->toBe('Stripe EU')
        ->and($payload['supports3ds'])->toBeTrue()
        ->and($payload['requireTermsOfServiceConsent'])->toBeTrue()
        // ObfuscatedString — masked with the last 3 characters kept.
        // ObfuscatedString — masked with the last 3 characters kept.
        ->and($payload['secretKey'])->toBe("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2026}def");

    expect(PaymentProvider::query()->where('organization_id', $organization->id)->count())->toBe(1);

    Queue::assertPushed(\App\Jobs\PaymentProviders\StripeRegisterWebhookJob::class);
})->group('ledger:gql:mutation:addStripePaymentProvider');

it('answers the secret_key value_is_mandatory validation on a new stripe provider', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $response = gqlPost(
        ADD_STRIPE_MUTATION,
        ['input' => ['code' => 'stripe-eu', 'name' => 'Stripe EU']],
        gqlAuthHeaders($user, $organization->id),
    );

    $extensions = $response->json('errors.0.extensions');

    expect($extensions['status'])->toBe(422)
        ->and($extensions['details'])->toBe(['secretKey' => ['value_is_mandatory']]);
})->group('ledger:gql:mutation:addStripePaymentProvider');

it('updates the stripe payment provider without overwriting the secret key', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $provider = PaymentProvider::factory()->forOrganization($organization)->create([
        'code' => 'stripe-eu',
        'name' => 'Stripe EU',
    ]);
    $secretBefore = $provider->secretKey();

    $response = gqlPost(
        UPDATE_STRIPE_MUTATION,
        ['input' => ['id' => $provider->id, 'name' => 'Stripe Renamed', 'supports3ds' => false]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.updateStripePaymentProvider');

    expect($payload['id'])->toBe($provider->id)
        ->and($payload['name'])->toBe('Stripe Renamed')
        ->and($payload['supports3ds'])->toBeFalse()
        ->and($provider->refresh()->secretKey())->toBe($secretBefore);

    Queue::assertNotPushed(\App\Jobs\PaymentProviders\StripeRegisterWebhookJob::class);
})->group('ledger:gql:mutation:updateStripePaymentProvider');

const ADD_ADYEN_MUTATION = <<<'GQL'
mutation($input: AddAdyenPaymentProviderInput!) {
    addAdyenPaymentProvider(input: $input) {
        ... on AdyenProvider { id code name merchantAccount livePrefix apiKey hmacKey }
    }
}
GQL;

const UPDATE_ADYEN_MUTATION = <<<'GQL'
mutation($input: UpdateAdyenPaymentProviderInput!) {
    updateAdyenPaymentProvider(input: $input) {
        ... on AdyenProvider { id code name merchantAccount }
    }
}
GQL;

it('adds the adyen payment provider with its secrets and settings', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $response = gqlPost(
        ADD_ADYEN_MUTATION,
        ['input' => [
            'code' => 'adyen-main',
            'name' => 'Adyen Main',
            'apiKey' => 'ady_key_123456789',
            'merchantAccount' => 'AcmeECOM',
            'livePrefix' => 'live123',
            'hmacKey' => 'hmac_abcdef123',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.addAdyenPaymentProvider');

    expect($payload['code'])->toBe('adyen-main')
        ->and($payload['merchantAccount'])->toBe('AcmeECOM')
        ->and($payload['livePrefix'])->toBe('live123')
        ->and($payload['apiKey'])->toBe("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2026}789")
        ->and($payload['hmacKey'])->toBe("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2026}123");

    $provider = PaymentProvider::query()->where('organization_id', $organization->id)->first();

    expect($provider->apiKey())->toBe('ady_key_123456789')
        ->and($provider->hmacKey())->toBe('hmac_abcdef123')
        ->and($provider->merchantAccount())->toBe('AcmeECOM');
})->group('ledger:gql:mutation:addAdyenPaymentProvider');

it('updates the adyen payment provider name', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $provider = PaymentProvider::factory()->forOrganization($organization)->create([
        'type' => 'PaymentProviders::AdyenProvider',
        'code' => 'adyen-main',
        'name' => 'Adyen Main',
        'secrets' => ['api_key' => 'ady_key_123456789'],
        'settings' => ['merchant_account' => 'AcmeECOM'],
    ]);

    $response = gqlPost(
        UPDATE_ADYEN_MUTATION,
        ['input' => ['id' => $provider->id, 'name' => 'Adyen Renamed']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('data.updateAdyenPaymentProvider.name'))->toBe('Adyen Renamed')
        ->and($response->json('data.updateAdyenPaymentProvider.code'))->toBe('adyen-main')
        // The code was not in the args — the denormalized customer code stays.
        ->and($provider->refresh()->name)->toBe('Adyen Renamed');
})->group('ledger:gql:mutation:updateAdyenPaymentProvider');

it('propagates a code change to the provider customers', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $provider = PaymentProvider::factory()->forOrganization($organization)->create([
        'code' => 'adyen-main',
        'name' => 'Adyen Main',
        'type' => 'PaymentProviders::AdyenProvider',
    ]);
    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'payment_provider' => 'adyen',
        'payment_provider_code' => 'adyen-main',
    ]);
    $connection = \App\Models\PaymentProviderCustomer::factory()->forCustomer($customer)->create([
        'type' => 'PaymentProviderCustomers::AdyenCustomer',
        'payment_provider_id' => $provider->id,
        'code' => 'adyen-main',
    ]);

    $response = gqlPost(
        UPDATE_ADYEN_MUTATION,
        ['input' => ['id' => $provider->id, 'code' => 'adyen-new']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors'))->toBeNull()
        ->and($response->json('data.updateAdyenPaymentProvider.code'))->toBe('adyen-new')
        // Rails: provider.customers.update_all(payment_provider_code:) — the
        // customer pointer moves; the connection rows keep their own code.
        ->and($connection->refresh()->code)->toBe('adyen-main')
        ->and($customer->refresh()->payment_provider_code)->toBe('adyen-new');
})->group('ledger:gql:mutation:updateAdyenPaymentProvider');

const ADD_CASHFREE_MUTATION = <<<'GQL'
mutation($input: AddCashfreePaymentProviderInput!) {
    addCashfreePaymentProvider(input: $input) {
        ... on CashfreeProvider { id code name clientId }
    }
}
GQL;

it('adds the cashfree payment provider', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $response = gqlPost(
        ADD_CASHFREE_MUTATION,
        ['input' => [
            'code' => 'cashfree-main',
            'name' => 'Cashfree Main',
            'clientId' => 'cf_client_123',
            'clientSecret' => 'cf_secret_123',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.addCashfreePaymentProvider');

    expect($payload['code'])->toBe('cashfree-main')
        ->and($payload['clientId'])->toBe('cf_client_123');

    $provider = PaymentProvider::query()->where('organization_id', $organization->id)->first();

    expect($provider->clientId())->toBe('cf_client_123')
        ->and($provider->clientSecret())->toBe('cf_secret_123');
})->group('ledger:gql:mutation:addCashfreePaymentProvider');

const ADD_FLUTTERWAVE_MUTATION = <<<'GQL'
mutation($input: AddFlutterwavePaymentProviderInput!) {
    addFlutterwavePaymentProvider(input: $input) {
        ... on FlutterwaveProvider { id code name secretKey webhookSecret }
    }
}
GQL;

it('adds the flutterwave payment provider with a generated webhook secret', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $response = gqlPost(
        ADD_FLUTTERWAVE_MUTATION,
        ['input' => ['code' => 'flw-main', 'name' => 'Flutterwave Main', 'secretKey' => 'flw_sk_123456789']],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.addFlutterwavePaymentProvider');

    expect($payload['code'])->toBe('flw-main')
        ->and($payload['secretKey'])->toBe("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2026}789")
        ->and($payload['webhookSecret'])->not->toBeNull();
})->group('ledger:gql:mutation:addFlutterwavePaymentProvider');

const ADD_GOCARDLESS_MUTATION = <<<'GQL'
mutation($input: AddGocardlessPaymentProviderInput!) {
    addGocardlessPaymentProvider(input: $input) {
        ... on GocardlessProvider { id code name hasAccessToken webhookSecret }
    }
}
GQL;

it('adds the gocardless payment provider with a webhook secret', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $response = gqlPost(
        ADD_GOCARDLESS_MUTATION,
        ['input' => ['code' => 'gc-main', 'name' => 'GoCardless Main']],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.addGocardlessPaymentProvider');

    expect($payload['code'])->toBe('gc-main')
        // The OAuth2 access_code exchange is TODO(port) — no token stored.
        ->and($payload['hasAccessToken'])->toBeFalse()
        ->and($payload['webhookSecret'])->not->toBeNull();
})->group('ledger:gql:mutation:addGocardlessPaymentProvider');

const ADD_MONEYHASH_MUTATION = <<<'GQL'
mutation($input: AddMoneyhashPaymentProviderInput!) {
    addMoneyhashPaymentProvider(input: $input) {
        ... on MoneyhashProvider { id code name apiKey flowId }
    }
}
GQL;

it('adds the moneyhash payment provider and fetches the signature key', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    Http::fake([
        '*/api/v1/organizations/get-webhook-signature-key/' => Http::response([
            'data' => ['webhook_signature_secret' => 'mh_sig_123'],
        ]),
    ]);

    $response = gqlPost(
        ADD_MONEYHASH_MUTATION,
        ['input' => [
            'code' => 'mh-main',
            'name' => 'Moneyhash Main',
            'apiKey' => 'mh_key_123456',
            'flowId' => 'flow_123',
        ]],
        gqlAuthHeaders($user, $organization->id),
    );

    $payload = $response->json('data.addMoneyhashPaymentProvider');

    expect($payload['code'])->toBe('mh-main')
        ->and($payload['apiKey'])->toBe('mh_key_123456')
        ->and($payload['flowId'])->toBe('flow_123');

    $provider = PaymentProvider::query()->where('organization_id', $organization->id)->first();

    expect($provider->signatureKey())->toBe('mh_sig_123');
})->group('ledger:gql:mutation:addMoneyhashPaymentProvider');

const UPDATE_MONEYHASH_MUTATION = <<<'GQL'
mutation($input: UpdateMoneyhashPaymentProviderInput!) {
    updateMoneyhashPaymentProvider(input: $input) {
        ... on MoneyhashProvider { id code name flowId }
    }
}
GQL;

it('updates the moneyhash payment provider flow id', function (): void {
    [$organization, $user] = gqlPaymentProvidersSetup();

    $provider = PaymentProvider::factory()->forOrganization($organization)->create([
        'type' => 'PaymentProviders::MoneyhashProvider',
        'code' => 'mh-main',
        'name' => 'Moneyhash Main',
        'secrets' => ['api_key' => 'mh_key_123456', 'signature_key' => 'mh_sig_123'],
        'settings' => ['flow_id' => 'flow_123'],
    ]);

    $response = gqlPost(
        UPDATE_MONEYHASH_MUTATION,
        ['input' => ['id' => $provider->id, 'flowId' => 'flow_456']],
        gqlAuthHeaders($user, $organization->id),
    );

    expect($response->json('errors'))->toBeNull()
        ->and($response->json('data.updateMoneyhashPaymentProvider.flowId'))->toBe('flow_456')
        ->and($provider->refresh()->flowId())->toBe('flow_456');
})->group('ledger:gql:mutation:updateMoneyhashPaymentProvider');
