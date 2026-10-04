<?php

declare(strict_types=1);

uses()->group(
    'ledger:rest:POST:/api/v1/customers/:external_id/wallets',
    'ledger:rest:GET:/api/v1/customers/:external_id/wallets',
    'ledger:rest:GET:/api/v1/customers/:external_id/wallets/:code',
    'ledger:rest:PUT:/api/v1/customers/:external_id/wallets/:code',
    'ledger:rest:DELETE:/api/v1/customers/:external_id/wallets/:code',
);

use App\Models\Wallet;
use App\Models\Customer;
use App\Enums\WalletStatus;
use App\Models\Organization;

/**
 * Port of Rails' spec/requests/api/v1/customers/wallets_controller_spec.rb
 * (plus the wallet_actions shared examples — the scenarios not repeated from
 * the top-level wallets controller test).
 *
 * Scenarios not ported (dependencies do not exist yet): payment methods,
 * recurring transaction rules, invoice custom sections, connections, and
 * the alerts/metadata subresources.
 */
function customerWalletOrganization(array $attributes = []): array
{
    $organization = Organization::factory()->create($attributes);

    return [$organization, $organization->apiKeys()->first()];
}

function customerWallet(Customer $customer, array $attributes = []): Wallet
{
    return Wallet::factory()->forCustomer($customer)->create($attributes);
}

// -- POST /api/v1/customers/:external_id/wallets ----------------------------------

it('creates a wallet for the route customer', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create(['currency' => 'EUR']);

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets', ['wallet' => [
        'external_customer_id' => $customer->external_id,
        'rate_amount' => '1',
        'name' => 'Wallet1',
        'currency' => 'EUR',
        'paid_credits' => '10',
        'granted_credits' => '10',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.external_customer_id', $customer->external_id)
        ->assertJsonPath('wallet.name', 'Wallet1');
});

it('uses the route customer external id when the wallet params omit it', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets', ['wallet' => [
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.external_customer_id', $customer->external_id);
});

it('uses the route customer external id even when the params carry another one', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets', ['wallet' => [
        'external_customer_id' => 'external-customer-id',
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.external_customer_id', $customer->external_id);
});

it('rejects creating a wallet with an existing active code', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    customerWallet($customer, ['name' => 'uniq wallet', 'code' => 'uniq_wallet']);

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets', ['wallet' => [
        'rate_amount' => '1',
        'name' => 'uniq wallet',
        'code' => 'uniq_wallet',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertUnprocessable()
        ->assertJsonPath('error_details', ['code' => ['value_already_exist']]);
});

it('allows reusing the code of a terminated wallet', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $terminated = customerWallet($customer, ['code' => 'uniq_wallet'])->refresh();
    $terminated->status = WalletStatus::Terminated;
    $terminated->terminated_at = now();
    $terminated->save();

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets', ['wallet' => [
        'rate_amount' => '1',
        'name' => 'uniq wallet',
        'code' => 'uniq_wallet',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.code', 'uniq_wallet');
});

it('derives a unique code when the derived one is taken', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $existing = customerWallet($customer, ['name' => 'uniq wallet', 'code' => 'uniq_wallet']);

    $this->postJson('/api/v1/customers/'.$customer->external_id.'/wallets', ['wallet' => [
        'rate_amount' => '1',
        'name' => 'uniq wallet',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.code', fn (string $code) => $code !== $existing->code && str_starts_with($code, 'uniq_wallet'));
});

it('returns not found when the route customer does not belong to the organization', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $otherCustomer = Customer::factory()->create();

    $this->postJson('/api/v1/customers/'.$otherCustomer->external_id.'/wallets', ['wallet' => [
        'rate_amount' => '1',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- PUT /api/v1/customers/:external_id/wallets/:code -----------------------------

it('updates the wallet by code', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = customerWallet($customer);

    $this->putJson('/api/v1/customers/'.$customer->external_id.'/wallets/'.$wallet->code, ['wallet' => [
        'name' => 'wallet1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.lago_id', $wallet->id)
        ->assertJsonPath('wallet.name', 'wallet1');
});

it('updates the active wallet when several share the code', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $other = customerWallet($customer, ['code' => 'shared_code']);
    $other->status = WalletStatus::Terminated;
    $other->terminated_at = now();
    $other->save();

    $wallet = customerWallet($customer, ['code' => 'shared_code']);

    $this->putJson('/api/v1/customers/'.$customer->external_id.'/wallets/shared_code', ['wallet' => [
        'name' => 'wallet1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    expect($wallet->refresh()->name)->toBe('wallet1')
        ->and($other->refresh()->name)->not->toBe('wallet1');
});

it('updates the active wallet even when the terminated one was created first', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $other = customerWallet($customer, ['code' => 'primary']);
    $other->status = WalletStatus::Terminated;
    $other->terminated_at = now();
    $other->save();

    $wallet = customerWallet($customer, ['code' => 'primary']);

    $this->putJson('/api/v1/customers/'.$customer->external_id.'/wallets/primary', ['wallet' => [
        'name' => 'wallet1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk();

    expect($wallet->refresh()->name)->toBe('wallet1')
        ->and($other->refresh()->name)->not->toBe('wallet1');
});

it('returns not found when the wallet code is unknown for the customer', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->putJson('/api/v1/customers/'.$customer->external_id.'/wallets/non-existing-code', ['wallet' => [
        'name' => 'wallet1',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- GET /api/v1/customers/:external_id/wallets/:code -----------------------------

it('returns the wallet by code', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = customerWallet($customer);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/wallets/'.$wallet->code, ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.lago_id', $wallet->id);
});

it('returns not found for an unknown route customer on show', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $otherCustomer = Customer::factory()->create();

    $this->getJson('/api/v1/customers/'.$otherCustomer->external_id.'/wallets/any-code', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns not found when the wallet code does not exist for the customer', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    customerWallet($customer);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/wallets/non-existing-code', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

it('returns the active wallet when several share the code', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $other = customerWallet($customer, ['code' => 'shared_code']);
    $other->status = WalletStatus::Terminated;
    $other->terminated_at = now();
    $other->save();

    $wallet = customerWallet($customer, ['code' => 'shared_code']);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/wallets/shared_code', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.lago_id', $wallet->id);
});

// -- DELETE /api/v1/customers/:external_id/wallets/:code --------------------------

it('terminates the wallet by code', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $wallet = customerWallet($customer);

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/wallets/'.$wallet->code, [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.lago_id', $wallet->id);

    expect($wallet->refresh()->statusEnum())->toBe(WalletStatus::Terminated);
});

it('terminates the active wallet when several share the code', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    $other = customerWallet($customer, ['code' => 'primary']);
    $other->status = WalletStatus::Terminated;
    $other->terminated_at = now();
    $other->save();

    $wallet = customerWallet($customer, ['code' => 'primary']);

    $this->deleteJson('/api/v1/customers/'.$customer->external_id.'/wallets/primary', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJsonPath('wallet.lago_id', $wallet->id);

    expect($wallet->refresh()->statusEnum())->toBe(WalletStatus::Terminated)
        ->and($other->refresh()->statusEnum())->toBe(WalletStatus::Terminated);
});

it('returns not found when terminating a wallet of an unknown customer', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();

    $this->deleteJson('/api/v1/customers/unknown-external/wallets/any-code', [], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- GET /api/v1/customers/:external_id/wallets -----------------------------------

it('returns the customer wallets with pagination', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    customerWallet($customer);
    customerWallet($customer);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/wallets?page=1&per_page=1', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json): void {
            $json->count('wallets', 1)
                ->where('meta.total_count', 2)
                ->etc();
        });
});

it('filters the customer wallets by currency', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();
    customerWallet($customer);
    $brl = customerWallet($customer, ['balance_currency' => 'BRL', 'consumed_amount_currency' => 'BRL']);

    $this->getJson('/api/v1/customers/'.$customer->external_id.'/wallets?currency=BRL', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertJson(function (Illuminate\Testing\Fluent\AssertableJson $json) use ($brl) {
            $json->count('wallets', 1)
                ->where('wallets.0.lago_id', $brl->id)
                ->etc();
        });
});

it('returns not found when the external customer id does not belong to the organization', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $otherCustomer = Customer::factory()->create();

    $this->getJson('/api/v1/customers/'.$otherCustomer->external_id.'/wallets', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertNotFound();
});

// -- v2 mirror ------------------------------------------------------------------

it('mirrors the nested wallets endpoints at v2 with the beta header', function (): void {
    [$organization, $apiKey] = customerWalletOrganization();
    $customer = Customer::factory()->forOrganization($organization)->create();

    $this->postJson('/api/v2/customers/'.$customer->external_id.'/wallets', ['wallet' => [
        'rate_amount' => '1',
        'name' => 'V2 Nested Wallet',
        'currency' => 'EUR',
    ]], ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('wallet.name', 'V2 Nested Wallet');

    $this->getJson('/api/v2/customers/'.$customer->external_id.'/wallets', ['Authorization' => 'Bearer '.$apiKey->value])
        ->assertOk()
        ->assertHeader('X-Lago-Endpoint-Status', 'beta')
        ->assertJsonPath('meta.total_count', 1);
});
