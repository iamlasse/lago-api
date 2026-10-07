<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/wallet_transactions',
    'ledger:rest:GET:/api/v1/wallet_transactions/:id',
    'ledger:rest:GET:/api/v1/wallets/:id/wallet_transactions',
);

use App\Models\Wallet;
use App\Models\Customer;
use Illuminate\Support\Str;
use App\Models\Organization;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Queue;

/**
 * Port of Rails' spec/requests/api/v1/wallet_transactions_controller_spec.rb
 * (create / index / show; payment_url, consumptions and fundings are not
 * registered — their services are not ported yet).
 *
 * Scenarios not ported: the invoice_custom_section payload (no
 * InvoiceCustomSection model).
 */
function walletTransactionOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function makeWalletTransaction(Wallet $wallet, array $attributes = []): WalletTransaction
{
    return WalletTransaction::factory()->forWallet($wallet)->create($attributes);
}

beforeEach(function (): void {
    Queue::fake();
});

// -- POST /api/v1/wallet_transactions ---------------------------------------------

it('creates paid and granted wallet transactions', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create([
        'credits_balance' => 10,
        'balance_cents' => 1000,
    ]);

    $this->postJson('/api/v1/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id,
        'paid_credits' => '10',
        'granted_credits' => '10',
        'name' => 'Custom Top-up Name',
        'purchase_order_number' => 'PO-789',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($wallet): void {
            $json->count('wallet_transactions', 2)
                ->where('wallet_transactions.0.status', 'pending')
                ->where('wallet_transactions.0.transaction_status', 'purchased')
                ->where('wallet_transactions.0.transaction_type', 'inbound')
                ->where('wallet_transactions.0.source', 'manual')
                ->where('wallet_transactions.0.name', 'Custom Top-up Name')
                ->where('wallet_transactions.0.lago_wallet_id', $wallet->id)
                ->where('wallet_transactions.0.purchase_order_number', 'PO-789')
                ->where('wallet_transactions.1.status', 'settled')
                ->where('wallet_transactions.1.transaction_status', 'granted')
                ->etc();
        });
});

it('rejects paid credits below the wallet minimum', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create([
        'credits_balance' => 10,
        'balance_cents' => 1000,
        'paid_top_up_min_amount_cents' => 2000,
    ]);

    $this->postJson('/api/v1/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id,
        'paid_credits' => '10',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details.paid_credits', ['amount_below_minimum']);
});

it('creates a voided transaction that drains the wallet balance', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    // Rails: create(:wallet, :with_inbound_transaction, ...) — a pool-wide
    // void drains the inbound grants' remaining amount.
    $wallet = Wallet::factory()->forCustomer($customer)->withInboundTransaction()->create([
        'credits_balance' => 20,
        'balance_cents' => 2000,
    ]);

    $this->postJson('/api/v1/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id,
        'voided_credits' => '10',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($wallet): void {
            $json->count('wallet_transactions', 1)
                ->where('wallet_transactions.0.status', 'settled')
                ->where('wallet_transactions.0.transaction_status', 'voided')
                ->where('wallet_transactions.0.transaction_type', 'outbound')
                ->where('wallet_transactions.0.lago_wallet_id', $wallet->id)
                ->etc();
        });

    expect((float) $wallet->refresh()->credits_balance)->toEqual(10.0);
});

it('creates the transactions with metadata', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create(['credits_balance' => 10, 'balance_cents' => 1000]);

    $this->postJson('/api/v1/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id,
        'paid_credits' => '10',
        'granted_credits' => '10',
        'metadata' => [['key' => 'valid_value', 'value' => 'also_valid']],
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallet_transactions', 2)
                ->where('wallet_transactions.0.metadata', [['key' => 'valid_value', 'value' => 'also_valid']])
                ->where('wallet_transactions.1.metadata', [['key' => 'valid_value', 'value' => 'also_valid']])
                ->etc();
        });
});

it('creates the transactions with a priority', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create(['credits_balance' => 10, 'balance_cents' => 1000]);

    $this->postJson('/api/v1/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id,
        'paid_credits' => '10',
        'granted_credits' => '10',
        'priority' => 1,
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallet_transactions', 2)
                ->where('wallet_transactions.0.priority', 1)
                ->where('wallet_transactions.1.priority', 1)
                ->etc();
        });
});

it('returns unprocessable when the wallet does not exist', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();

    $this->postJson('/api/v1/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id.'123',
        'paid_credits' => '10',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable();
});

// -- GET /api/v1/wallets/:id/wallet_transactions ----------------------------------

it('returns the wallet transactions of the wallet', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    $first = makeWalletTransaction($wallet);
    $second = makeWalletTransaction($wallet);
    // A transaction of another wallet does not appear.
    makeWalletTransaction(Wallet::factory()->forCustomer($customer)->create());

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallet_transactions', 2)
                ->etc();
        });

    $ids = WalletTransaction::query()->whereIn('id', [$first->id, $second->id])->pluck('id');

    expect($ids)->toContain($first->id, $second->id);
});

it('paginates the wallet transactions with metadata', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    makeWalletTransaction($wallet);
    makeWalletTransaction($wallet);

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions?page=1&per_page=1', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallet_transactions', 1)
                ->where('meta.current_page', 1)
                ->where('meta.next_page', 2)
                ->where('meta.prev_page', null)
                ->where('meta.total_pages', 2)
                ->where('meta.total_count', 2)
                ->etc();
        });
});

it('filters the wallet transactions by status', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    makeWalletTransaction($wallet);
    $pending = makeWalletTransaction($wallet, ['status' => App\Enums\WalletTransactionStatus::Pending, 'settled_at' => null]);

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions?status=pending', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($pending): void {
            $json->count('wallet_transactions', 1)
                ->where('wallet_transactions.0.lago_id', $pending->id)
                ->etc();
        });
});

it('filters the wallet transactions by transaction type', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    makeWalletTransaction($wallet);
    $outbound = makeWalletTransaction($wallet, [
        'transaction_type' => App\Enums\WalletTransactionType::Outbound,
        'transaction_status' => App\Enums\WalletTransactionCreditStatus::Voided,
    ]);

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions?transaction_type=outbound', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($outbound): void {
            $json->count('wallet_transactions', 1)
                ->where('wallet_transactions.0.lago_id', $outbound->id)
                ->etc();
        });
});

it('filters the wallet transactions by transaction status', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    makeWalletTransaction($wallet, ['transaction_status' => App\Enums\WalletTransactionCreditStatus::Purchased]);
    $voided = makeWalletTransaction($wallet, ['transaction_status' => App\Enums\WalletTransactionCreditStatus::Voided]);
    makeWalletTransaction($wallet, ['transaction_status' => App\Enums\WalletTransactionCreditStatus::Invoiced]);

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions?transaction_status=voided', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($voided): void {
            $json->count('wallet_transactions', 1)
                ->where('wallet_transactions.0.lago_id', $voided->id)
                ->etc();
        });
});

it('ignores an invalid transaction_status value', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    makeWalletTransaction($wallet);
    makeWalletTransaction($wallet);

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions?transaction_status=invalid', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallet_transactions', 2)
                ->etc();
        });
});

it('filters the wallet transactions by a metadata pair', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    $alpha = makeWalletTransaction($wallet, ['metadata' => [['key' => 'site_id', 'value' => 'alpha']]]);
    makeWalletTransaction($wallet, ['metadata' => [['key' => 'site_id', 'value' => 'beta']]]);

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions?metadata[site_id]=alpha', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($alpha): void {
            $json->count('wallet_transactions', 1)
                ->where('wallet_transactions.0.lago_id', $alpha->id)
                ->etc();
        });
});

it('ignores a malformed metadata filter', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    makeWalletTransaction($wallet);
    makeWalletTransaction($wallet);

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions?metadata=foo', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallet_transactions', 2)
                ->etc();
        });
});

it('returns not found when the wallet does not exist', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();

    $this->getJson('/api/v1/wallets/'.Str::uuid().'/wallet_transactions', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- GET /api/v1/wallet_transactions/:id ------------------------------------------

it('returns a wallet transaction', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();
    $transaction = makeWalletTransaction($wallet);

    $this->getJson('/api/v1/wallet_transactions/'.$transaction->id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet_transaction.lago_id', $transaction->id)
        ->assertJsonPath('wallet_transaction.lago_wallet_id', $wallet->id);
});

it('returns not found when the transaction belongs to another organization', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $otherCustomer = Customer::factory()->create();
    $otherWallet = Wallet::factory()->forCustomer($otherCustomer)->create();
    $transaction = makeWalletTransaction($otherWallet);

    $this->getJson('/api/v1/wallet_transactions/'.$transaction->id, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not found when the wallet transaction does not exist', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();

    $this->getJson('/api/v1/wallet_transactions/'.Str::uuid(), ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- permissions ---------------------------------------------------------------------

it('requires an api permission to write wallet transactions', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['wallet_transaction' => ['read']]), $apiKey->id],
    );

    $this->postJson('/api/v1/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id,
        'paid_credits' => '10',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertJsonPath('code', 'write_action_not_allowed_for_wallet_transaction');
});

it('requires an api permission to read wallet transactions', function (): void {
    config(['lago.license' => 'premium-license-token']);

    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();

    Illuminate\Support\Facades\DB::update(
        'update organizations set premium_integrations = ARRAY[?]::varchar[] where id = ?',
        ['api_permissions', $organization->id],
    );
    Illuminate\Support\Facades\DB::update(
        'update api_keys set permissions = ?::jsonb where id = ?',
        [json_encode(['wallet_transaction' => ['write']]), $apiKey->id],
    );

    $this->getJson('/api/v1/wallets/'.$wallet->id.'/wallet_transactions', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertForbidden()
        ->assertJsonPath('code', 'read_action_not_allowed_for_wallet_transaction');
});

// -- v2 mirror ------------------------------------------------------------------

it('mirrors the wallet transaction endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = walletTransactionOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = Wallet::factory()->forCustomer($customer)->create();

    $this->postJson('/api/v2/wallet_transactions', ['wallet_transaction' => [
        'wallet_id' => $wallet->id,
        'granted_credits' => '5',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallet_transactions', 1)
                ->etc();
        });

    $this->getJson('/api/v2/wallets/'.$wallet->id.'/wallet_transactions', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 1);
});
