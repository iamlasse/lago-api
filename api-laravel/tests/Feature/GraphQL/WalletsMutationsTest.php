<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Wallet;
use App\Models\Customer;
use App\Enums\WalletStatus;
use App\Models\BillingEntity;
use Illuminate\Support\Facades\Queue;

/**
 * Ports of Rails' spec/graphql/mutations/{wallets/{create,update,terminate},
 * wallet_transactions/create}_spec.rb over the frozen SDL.
 *
 * Scenarios not ported (dependencies do not exist yet):
 * - the recurring_transaction_rules assertions (no model — accepted and
 *   ignored, the services TODO the slice);
 * - connections (multi_connection feature flag + BillingObjectConnections);
 * - the permission gate ("wallets:create" / "wallets:update" /
 *   "wallets:terminate" / "wallets:top_up") — context permissions are not
 *   populated until the roles/Permission slice lands (same as every ported
 *   mutation).
 */
function gqlWalletMutationsSetup(): array
{
    $organization = gqlCreateOrganization();
    BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlMakeMutationWallet(object $organization, array $attributes = []): Wallet
{
    $customer = $attributes['customer'] ?? Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    unset($attributes['customer']);

    return Wallet::factory()->forCustomer($customer)->create($attributes);
}

const CREATE_WALLET_MUTATION = <<<'GQL'
mutation ($input: CreateCustomerWalletInput!) {
    createCustomerWallet(input: $input) {
        id
        code
        name
        priority
        purchaseOrderNumber
        rateAmount
        status
        currency
        expirationAt
        invoiceRequiresSuccessfulPayment
        paidTopUpMinAmountCents
        paidTopUpMaxAmountCents
        metadata { key value }
    }
}
GQL;

it('creates a wallet', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();
    Queue::fake();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $expirationAt = now()->addYear()->startOfSecond()->toISOString();

    $response = gqlPost(CREATE_WALLET_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'name' => 'First Wallet',
        'priority' => 9,
        'purchaseOrderNumber' => 'PO-123',
        'rateAmount' => '1',
        'paidCredits' => '10.00',
        'grantedCredits' => '0.00',
        'expirationAt' => $expirationAt,
        'currency' => 'EUR',
        'invoiceRequiresSuccessfulPayment' => true,
        'paidTopUpMinAmountCents' => 100,
        'paidTopUpMaxAmountCents' => 10000,
    ]], gqlAuthHeaders($user, $organization->id));

    $data = $response->json('data.createCustomerWallet');

    expect($data['id'])->toBeString()
        ->and($data['code'])->toBe('first_wallet')
        ->and($data['name'])->toBe('First Wallet')
        ->and($data['priority'])->toBe(9)
        ->and($data['purchaseOrderNumber'])->toBe('PO-123')
        ->and($data['rateAmount'])->toEqualWithDelta(1.0, 0.00001)
        ->and($data['status'])->toBe('active')
        ->and($data['invoiceRequiresSuccessfulPayment'])->toBeTrue()
        ->and($data['paidTopUpMinAmountCents'])->toBe('100')
        ->and($data['paidTopUpMaxAmountCents'])->toBe('10000');

    expect(Wallet::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

it('creates a wallet with a default code when the name is absent', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $response = gqlPost(CREATE_WALLET_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'name' => null,
        'priority' => 11,
        'rateAmount' => '1',
        'paidCredits' => '0.00',
        'grantedCredits' => '0.00',
        'expirationAt' => now()->addYear()->toISOString(),
        'currency' => 'EUR',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createCustomerWallet.code'))->toBe('default')
        ->and($response->json('data.createCustomerWallet.name'))->toBeNull();
});

it('creates a wallet with the provided code', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $response = gqlPost(CREATE_WALLET_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'name' => 'My Wallet',
        'code' => 'custom_code',
        'priority' => 9,
        'rateAmount' => '1',
        'paidCredits' => '0.00',
        'grantedCredits' => '0.00',
        'expirationAt' => now()->addYear()->toISOString(),
        'currency' => 'EUR',
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createCustomerWallet.code'))->toBe('custom_code')
        ->and($response->json('data.createCustomerWallet.name'))->toBe('My Wallet');
});

it('returns an error when the code is already taken for the customer', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    gqlMakeMutationWallet($organization, ['customer' => $customer, 'code' => 'existing_code']);

    $response = gqlPost(CREATE_WALLET_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'name' => 'My Wallet',
        'code' => 'existing_code',
        'priority' => 9,
        'rateAmount' => '1',
        'paidCredits' => '0.00',
        'grantedCredits' => '0.00',
        'expirationAt' => now()->addYear()->toISOString(),
        'currency' => 'EUR',
    ]], gqlAuthHeaders($user, $organization->id));

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('unprocessable_entity')
        ->and($error['extensions']['details']['code'])->toBe(['value_already_exist']);
});

it('creates a wallet with metadata', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);

    $response = gqlPost(CREATE_WALLET_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'name' => 'Wallet with Metadata',
        'priority' => 9,
        'rateAmount' => '1',
        'paidCredits' => '0.00',
        'grantedCredits' => '0.00',
        'expirationAt' => now()->addYear()->toISOString(),
        'currency' => 'EUR',
        'metadata' => [
            ['key' => 'env', 'value' => 'production'],
            ['key' => 'team', 'value' => 'engineering'],
        ],
    ]], gqlAuthHeaders($user, $organization->id));

    expect($response->json('data.createCustomerWallet.metadata'))->toEqual([
        ['key' => 'env', 'value' => 'production'],
        ['key' => 'team', 'value' => 'engineering'],
    ]);
});

it('requires a signed-in user to create a wallet', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(CREATE_WALLET_MUTATION, ['input' => [
        'customerId' => $customer->id,
        'rateAmount' => '1',
        'paidCredits' => '0.00',
        'grantedCredits' => '0.00',
        'currency' => 'EUR',
        'priority' => 1,
    ]]);

    expect($response->json('errors.0.extensions.status'))->toBe('unauthorized');
});

// -- updateCustomerWallet ----------------------------------------------------------

it('updates a wallet', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();
    Queue::fake();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    $wallet = gqlMakeMutationWallet($organization, ['customer' => $customer]);

    $response = gqlPost(
        'mutation ($input: UpdateCustomerWalletInput!) { updateCustomerWallet(input: $input) { id name priority invoiceRequiresSuccessfulPayment } }',
        ['input' => ['id' => $wallet->id, 'name' => 'wallet1', 'priority' => 5, 'invoiceRequiresSuccessfulPayment' => true]],
        gqlAuthHeaders($user, $organization->id),
    );

    $data = $response->json('data.updateCustomerWallet');

    expect($data['id'])->toBe($wallet->id)
        ->and($data['name'])->toBe('wallet1')
        ->and($data['priority'])->toBe(5)
        ->and($data['invoiceRequiresSuccessfulPayment'])->toBeTrue();
});

it('returns an error when updating an unknown wallet', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $response = gqlPost(
        'mutation ($input: UpdateCustomerWalletInput!) { updateCustomerWallet(input: $input) { id } }',
        ['input' => ['id' => 'foo', 'name' => 'wallet1', 'priority' => 5]],
        gqlAuthHeaders($user, $organization->id),
    );

    $error = $response->json('errors.0');

    expect($error['extensions']['status'])->toBe(404);
});

// -- terminateCustomerWallet -------------------------------------------------------

it('terminates a wallet', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();
    Queue::fake();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    $wallet = gqlMakeMutationWallet($organization, ['customer' => $customer]);

    $response = gqlPost(
        'mutation ($input: TerminateCustomerWalletInput!) { terminateCustomerWallet(input: $input) { id name status terminatedAt } }',
        ['input' => ['id' => $wallet->id]],
        gqlAuthHeaders($user, $organization->id),
    );

    $data = $response->json('data.terminateCustomerWallet');

    expect($data['id'])->toBe($wallet->id)
        ->and($data['name'])->toBe($wallet->name)
        ->and($data['status'])->toBe('terminated')
        ->and($data['terminatedAt'])->toBeString();

    expect($wallet->refresh()->statusEnum())->toBe(WalletStatus::Terminated);
});

// -- createCustomerWalletTransaction -----------------------------------------------

const CREATE_WALLET_TRANSACTION_MUTATION = <<<'GQL'
mutation ($input: CreateCustomerWalletTransactionInput!) {
    createCustomerWalletTransaction(input: $input) {
        collection {
            id
            status
            priority
            source
            name
            purchaseOrderNumber
            transactionStatus
            transactionType
            creditAmount
            amount
            metadata { key value }
        }
        metadata { totalCount }
    }
}
GQL;

it('creates a wallet transaction', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    $wallet = gqlMakeMutationWallet($organization, [
        'customer' => $customer,
        'credits_balance' => 10,
        'balance_cents' => 1000,
    ]);

    $response = gqlPost(CREATE_WALLET_TRANSACTION_MUTATION, ['input' => [
        'walletId' => $wallet->id,
        'name' => 'Test Transaction',
        'paidCredits' => '5.00',
        'grantedCredits' => '15.00',
        'priority' => 25,
        'purchaseOrderNumber' => 'PO-123',
        'metadata' => [
            ['key' => 'fixed', 'value' => '0'],
        ],
    ]], gqlAuthHeaders($user, $organization->id));

    $collection = $response->json('data.createCustomerWalletTransaction.collection');

    $granted = collect($collection)->firstWhere('transactionStatus', 'granted');
    $purchased = collect($collection)->firstWhere('transactionStatus', 'purchased');

    expect($collection)->toHaveCount(2)
        ->and($response->json('data.createCustomerWalletTransaction.metadata.totalCount'))->toBe(2)
        ->and($granted['status'])->toBe('settled')
        ->and($granted['creditAmount'])->toBe('15.0')
        ->and($granted['transactionType'])->toBe('inbound')
        ->and($granted['source'])->toBe('manual')
        ->and($granted['name'])->toBe('Test Transaction')
        ->and($purchased['status'])->toBe('pending')
        ->and($purchased['creditAmount'])->toBe('5.0')
        ->and($purchased['priority'])->toBe(25)
        ->and($purchased['purchaseOrderNumber'])->toBe('PO-123')
        ->and($purchased['metadata'])->toEqual([['key' => 'fixed', 'value' => '0']]);
});

it('returns an error when the wallet has a minimum amount', function (): void {
    [$organization, $user] = gqlWalletMutationsSetup();

    $customer = Customer::factory()->create([
        'organization_id' => $organization->id,
        'currency' => 'EUR',
    ]);
    $wallet = gqlMakeMutationWallet($organization, [
        'customer' => $customer,
        'credits_balance' => 10,
        'balance_cents' => 1000,
        'paid_top_up_min_amount_cents' => 1000,
    ]);

    $response = gqlPost(CREATE_WALLET_TRANSACTION_MUTATION, ['input' => [
        'walletId' => $wallet->id,
        'paidCredits' => '5.00',
    ]], gqlAuthHeaders($user, $organization->id));

    $error = $response->json('errors.0');

    expect($error['extensions']['code'])->toBe('unprocessable_entity')
        ->and($error['extensions']['details']['paidCredits'])->toBe(['amount_below_minimum']);
});
