<?php

declare(strict_types=1);

require_once __DIR__.'/GraphQLHelpers.php';
require_once __DIR__.'/AuthPlumbingTest.php';

use App\Models\Wallet;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\BillingEntity;
use App\Models\WalletTransaction;

/**
 * Ports of Rails' spec/graphql/resolvers/{wallet_resolver,
 * wallets_resolver, wallet_transaction_resolver,
 * wallet_transactions_resolver}_spec.rb over the frozen SDL.
 *
 * Ledger rows: gql:query:wallet, gql:query:wallets, gql:query:walletTransaction,
 * gql:query:walletTransactions.
 */
function gqlWalletsSetup(): array
{
    $organization = gqlCreateOrganization();
    // Rails creates the default billing entity with the organization; the
    // frozen-schema port creates it explicitly in the fixture.
    BillingEntity::factory()->create(['organization_id' => $organization->id]);
    $user = gqlCreateUser();
    gqlCreateMembership($user, $organization);

    return [$organization->refresh(), $user];
}

function gqlMakeWallet(object $organization, array $attributes = []): Wallet
{
    $customer = $attributes['customer'] ?? Customer::factory()->create([
        'organization_id' => $organization->id,
    ]);
    unset($attributes['customer']);

    return Wallet::factory()->forCustomer($customer)->create($attributes);
}

// -- query { wallet(id:) } ---------------------------------------------------------

const WALLET_QUERY = <<<'GQL'
query ($id: ID!) {
    wallet(id: $id) {
        id
        name
        status
        creditsBalance
        metadata { key value }
    }
}
GQL;

it('returns a single wallet', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $wallet = gqlMakeWallet($organization, ['name' => 'My Wallet']);

    $response = gqlPost(WALLET_QUERY, ['id' => $wallet->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.wallet');

    expect($payload['id'])->toBe($wallet->id)
        ->and($payload['name'])->toBe('My Wallet')
        ->and($payload['status'])->toBe('active')
        ->and($payload['metadata'])->toBeNull();
});

it('returns not found for an unknown wallet id', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $response = gqlPost(WALLET_QUERY, ['id' => 'foo'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $error = $response->json('errors.0');

    expect($error['message'])->toBe('Resource not found')
        ->and($error['extensions']['status'])->toBe(404)
        ->and($error['extensions']['code'])->toBe('not_found')
        ->and($response->json('data.wallet'))->toBeNull();
});

it('returns not found for a wallet of another organization', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $otherWallet = Wallet::factory()->create();

    $response = gqlPost(WALLET_QUERY, ['id' => $otherWallet->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('errors.0.extensions.code'))->toBe('not_found');
});

// -- query { wallets(customerId:) } ------------------------------------------------

const WALLETS_QUERY = <<<'GQL'
query ($customerId: ID!) {
    wallets(customerId: $customerId, limit: 10) {
        collection { id status }
        metadata { currentPage limitValue totalPages totalCount customerActiveWalletsCount }
    }
}
GQL;

it('returns the customer wallets ordered with active first', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    $active = gqlMakeWallet($organization, ['customer' => $customer]);
    $terminated = gqlMakeWallet($organization, ['customer' => $customer]);
    $terminated->status = App\Enums\WalletStatus::Terminated;
    $terminated->terminated_at = now();
    $terminated->save();

    $response = gqlPost(WALLETS_QUERY, ['customerId' => $customer->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.wallets');

    expect($payload['collection'])->toHaveCount(2)
        ->and($payload['collection'][0]['id'])->toBe($active->id)
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalPages'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(2)
        // The customer has exactly one active wallet.
        ->and($payload['metadata']['customerActiveWalletsCount'])->toBe(1);
});

it('filters the wallets by status and ids', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $customer = Customer::factory()->create(['organization_id' => $organization->id]);
    gqlMakeWallet($organization, ['customer' => $customer]);
    $terminated = gqlMakeWallet($organization, ['customer' => $customer]);
    $terminated->status = App\Enums\WalletStatus::Terminated;
    $terminated->terminated_at = now();
    $terminated->save();

    $statusQuery = <<<'GQL'
query ($customerId: ID!, $status: WalletStatusEnum) {
    wallets(customerId: $customerId, status: $status) {
        collection { id }
        metadata { totalCount }
    }
}
GQL;

    $response = gqlPost($statusQuery, ['customerId' => $customer->id, 'status' => 'terminated'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    expect($response->json('data.wallets.collection'))->toHaveCount(1)
        ->and($response->json('data.wallets.collection.0.id'))->toBe($terminated->id);
});

it('returns not found for an unknown wallets customer', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $response = gqlPost(WALLETS_QUERY, ['customerId' => 'foo'], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $error = $response->json('errors.0');

    expect($error['extensions']['status'])->toBe(404);
});

it('requires the organization header on the wallets query', function (): void {
    [$organization, $user] = gqlWalletsSetup();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    $response = gqlPost(WALLETS_QUERY, ['customerId' => $customer->id], gqlAuthHeaders($user));

    $response->assertOk();

    expect($response->json('errors.0.message'))->toBe('Missing organization id');
});

// -- query { walletTransactions(walletId:) } ---------------------------------------

const WALLET_TRANSACTIONS_QUERY = <<<'GQL'
query ($walletId: ID!, $status: WalletTransactionStatusEnum) {
    walletTransactions(walletId: $walletId, limit: 5, status: $status) {
        collection { id status transactionStatus transactionType }
        metadata { currentPage totalCount }
    }
}
GQL;

it('returns the wallet transactions of a wallet', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $wallet = gqlMakeWallet($organization);
    $transaction = WalletTransaction::factory()->forWallet($wallet)->create();

    $response = gqlPost(
        WALLET_TRANSACTIONS_QUERY,
        ['walletId' => $wallet->id, 'status' => null],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.walletTransactions');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($transaction->id)
        ->and($payload['metadata']['currentPage'])->toBe(1)
        ->and($payload['metadata']['totalCount'])->toBe(1);
});

it('filters the wallet transactions by status', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $wallet = gqlMakeWallet($organization);
    WalletTransaction::factory()->forWallet($wallet)->create();
    $pending = WalletTransaction::factory()->forWallet($wallet)->create([
        'status' => App\Enums\WalletTransactionStatus::Pending,
        'settled_at' => null,
    ]);

    $response = gqlPost(
        WALLET_TRANSACTIONS_QUERY,
        ['walletId' => $wallet->id, 'status' => 'pending'],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    $payload = $response->json('data.walletTransactions');

    expect($payload['collection'])->toHaveCount(1)
        ->and($payload['collection'][0]['id'])->toBe($pending->id)
        ->and($payload['collection'][0]['status'])->toBe('pending');
});

it('returns not found for an unknown wallet transactions wallet', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $response = gqlPost(
        WALLET_TRANSACTIONS_QUERY,
        ['walletId' => 'foo', 'status' => null],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    expect($response->json('errors.0.extensions.status'))->toBe(404);
});

// -- query { walletTransaction(id:) } ----------------------------------------------

it('returns a single wallet transaction', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $wallet = gqlMakeWallet($organization);
    $transaction = WalletTransaction::factory()->forWallet($wallet)->create();

    $query = <<<'GQL'
query ($id: ID!) {
    walletTransaction(id: $id) {
        id
        walletName
        status
    }
}
GQL;

    $response = gqlPost($query, ['id' => $transaction->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $payload = $response->json('data.walletTransaction');

    expect($payload['id'])->toBe($transaction->id)
        ->and($payload['walletName'])->toBe($wallet->name)
        ->and($payload['status'])->toBe('settled');
});

it('returns not found for an unknown wallet transaction', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $response = gqlPost(
        'query ($id: ID!) { walletTransaction(id: $id) { id } }',
        ['id' => 'foo'],
        gqlAuthHeaders($user, $organization->id),
    );

    $response->assertOk();

    expect($response->json('errors.0.extensions.status'))->toBe(404);
});

// -- wallet { recurringTransactionRules } -----------------------------------------

it('returns the active recurring transaction rules of a wallet', function (): void {
    [$organization, $user] = gqlWalletsSetup();

    $wallet = gqlMakeWallet($organization);
    $activeRule = App\Models\RecurringTransactionRule::factory()->forWallet($wallet)->create();
    $expiredRule = App\Models\RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'expiration_at' => now()->subDay(),
    ]);
    App\Models\RecurringTransactionRule::factory()->forWallet($wallet)->create([
        'status' => App\Enums\RecurringTransactionRuleStatus::Terminated->value,
    ]);

    $query = <<<'GQL'
query ($id: ID!) {
    wallet(id: $id) {
        id
        recurringTransactionRules {
            lagoId
            trigger
            interval
            method
            paidCredits
            grantedCredits
        }
    }
}
GQL;

    $response = gqlPost($query, ['id' => $wallet->id], gqlAuthHeaders($user, $organization->id));

    $response->assertOk();

    $rules = $response->json('data.wallet.recurringTransactionRules');

    expect(count($rules))->toBe(1)
        ->and($rules[0]['lagoId'])->toBe($activeRule->id)
        ->and($rules[0]['trigger'])->toBe('interval')
        ->and($rules[0]['interval'])->toBe('monthly')
        ->and($rules[0]['method'])->toBe('fixed')
        ->and($rules[0]['paidCredits'])->toBe('10.0')
        ->and($rules[0]['grantedCredits'])->toBe('10.0');
});
