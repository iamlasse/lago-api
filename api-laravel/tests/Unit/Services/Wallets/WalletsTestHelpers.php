<?php

declare(strict_types=1);

use App\Models\Wallet;
use App\Models\Customer;

/**
 * Shared setup for the Wallets / WalletTransactions service tests: an
 * organization, its customer and a wallet factory helper.
 */
function walletSetup(): array
{
    $organization = App\Models\Organization::factory()->create();
    $customer = Customer::factory()->create(['organization_id' => $organization->id]);

    return [$organization, $customer];
}

function walletFor(Customer $customer, array $overrides = []): Wallet
{
    return Wallet::factory()->forCustomer($customer)->create(array_merge([
        'rate_amount' => '1',
    ], $overrides));
}

function terminatedWalletFor(Customer $customer): Wallet
{
    return Wallet::factory()->forCustomer($customer)->terminated()->create([
        'rate_amount' => '1',
    ]);
}
