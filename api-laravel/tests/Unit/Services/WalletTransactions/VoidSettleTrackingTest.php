<?php

declare(strict_types=1);

require_once __DIR__.'/../Wallets/WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Support\CurrentContext;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionCreditStatus;
use App\Models\WalletTransactionConsumption;
use App\Services\Failures\ValidationFailure;
use App\Services\WalletTransactions\VoidService;
use App\Services\WalletTransactions\SettleService;
use App\Services\WalletTransactions\MarkAsFailedService;
use App\Services\WalletTransactions\TrackConsumptionService;

beforeEach(function (): void {
    CurrentContext::reset();
});

function grantFor(Wallet $wallet, array $overrides = []): WalletTransaction
{
    return WalletTransaction::factory()->forWallet($wallet)->create(array_merge([
        'transaction_type' => WalletTransactionType::Inbound,
        'transaction_status' => WalletTransactionCreditStatus::Granted,
        'status' => WalletTransactionStatus::Settled,
        'amount' => '10',
        'credit_amount' => '10',
        'settled_at' => now(),
        'remaining_amount_cents' => 1000,
    ], $overrides));
}

it('voids credits, decreases the balance and tracks the consumption on traceable wallets', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true, 'balance_cents' => 1000, 'credits_balance' => '10.00000']);
    $grant = grantFor($wallet);

    $result = VoidService::call(
        wallet: $wallet,
        walletCredit: new App\Support\WalletCredit(wallet: $wallet, creditAmount: '3', invoiceable: false),
        inboundWalletTransaction: $grant,
    );

    expect($result->success())->toBeTrue();

    $voided = $result->wallet_transaction;

    expect($voided->transactionTypeEnum())->toBe(WalletTransactionType::Outbound)
        ->and($voided->transactionStatusEnum())->toBe(WalletTransactionCreditStatus::Voided)
        ->and($voided->statusEnum())->toBe(WalletTransactionStatus::Settled)
        ->and($voided->credit_amount)->toBe('3.00000')
        ->and($voided->billing_entity_id)->toBe($grant->billing_entity_id);

    $wallet->refresh();

    expect($wallet->balance_cents)->toBe(700)
        ->and($wallet->credits_balance)->toBe('7.00000')
        ->and($wallet->consumed_credits)->toBe('3.00000');

    // The consumption ledger drew from the targeted grant.
    expect(WalletTransactionConsumption::query()->count())->toBe(1)
        ->and(WalletTransactionConsumption::query()->first()->inbound_wallet_transaction_id)->toBe($grant->id)
        ->and(WalletTransactionConsumption::query()->first()->outbound_wallet_transaction_id)->toBe($voided->id)
        ->and($grant->refresh()->remaining_amount_cents)->toBe(700);
})->group('ledger:svc:WalletTransactions.VoidService');

it('rejects voids exceeding the grant remaining amount', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true, 'balance_cents' => 1000, 'credits_balance' => '10.00000']);
    $grant = grantFor($wallet, ['remaining_amount_cents' => 100]);

    $result = VoidService::call(
        wallet: $wallet,
        walletCredit: new App\Support\WalletCredit(wallet: $wallet, creditAmount: '5', invoiceable: false),
        inboundWalletTransaction: $grant,
    );

    expect($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['amount_cents' => ['exceeds_remaining_transaction_amount']]);
})->group('ledger:svc:WalletTransactions.VoidService');

it('voids the whole remaining amount of a specific grant', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true, 'balance_cents' => 1000, 'credits_balance' => '10.00000']);
    $grant = grantFor($wallet, ['remaining_amount_cents' => 400]);

    $result = VoidService::call(
        wallet: $wallet,
        inboundWalletTransaction: $grant,
        voidRemaining: true,
    );

    expect($result->success())->toBeTrue();

    $wallet->refresh();

    expect($result->wallet_transaction->credit_amount)->toBe('4.00000')
        ->and($wallet->balance_cents)->toBe(600)
        ->and($grant->refresh()->remaining_amount_cents)->toBe(0);
})->group('ledger:svc:WalletTransactions.VoidService');

it('settles a pending transaction and seeds the remaining ledger', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true]);

    $pending = WalletTransaction::factory()->forWallet($wallet)->create([
        'transaction_type' => WalletTransactionType::Inbound,
        'transaction_status' => WalletTransactionCreditStatus::Purchased,
        'status' => WalletTransactionStatus::Pending,
        'amount' => '8',
        'credit_amount' => '8',
        'remaining_amount_cents' => null,
    ]);

    $result = SettleService::call(walletTransaction: $pending);

    expect($result->success())->toBeTrue();

    $pending->refresh();

    expect($pending->statusEnum())->toBe(WalletTransactionStatus::Settled)
        ->and($pending->settled_at)->not->toBeNull()
        ->and($pending->remaining_amount_cents)->toBe(800);
})->group('ledger:svc:WalletTransactions.SettleService');

it('marks pending transactions as failed and leaves settled ones alone', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);

    $pending = WalletTransaction::factory()->forWallet($wallet)->create([
        'status' => WalletTransactionStatus::Pending,
        'remaining_amount_cents' => null,
    ]);

    $result = MarkAsFailedService::call(walletTransaction: $pending);

    expect($result->success())->toBeTrue()
        ->and($pending->refresh()->isFailed())->toBeTrue()
        ->and($pending->failed_at)->not->toBeNull();

    $settled = WalletTransaction::factory()->forWallet($wallet)->create([
        'status' => WalletTransactionStatus::Settled,
        'remaining_amount_cents' => 100,
    ]);

    MarkAsFailedService::call(walletTransaction: $settled);

    expect($settled->refresh()->isSettled())->toBeTrue();

    $missing = MarkAsFailedService::call(walletTransaction: null);
    expect($missing->success())->toBeTrue();
})->group('ledger:svc:WalletTransactions.MarkAsFailedService');

it('tracks consumption by priority with granted transactions first', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true, 'balance_cents' => 0, 'credits_balance' => '0']);

    $oldPurchased = grantFor($wallet, ['credit_amount' => '2', 'amount' => '2', 'remaining_amount_cents' => 200, 'priority' => 10, 'transaction_status' => WalletTransactionCreditStatus::Purchased, 'created_at' => now()->subDays(2)]);
    $granted = grantFor($wallet, ['credit_amount' => '3', 'amount' => '3', 'remaining_amount_cents' => 300, 'priority' => 10, 'created_at' => now()->subDay()]);
    $newerPurchased = grantFor($wallet, ['credit_amount' => '5', 'amount' => '5', 'remaining_amount_cents' => 500, 'priority' => 20, 'transaction_status' => WalletTransactionCreditStatus::Purchased, 'created_at' => now()]);

    $outbound = WalletTransaction::factory()->forWallet($wallet)->create([
        'transaction_type' => WalletTransactionType::Outbound,
        'transaction_status' => WalletTransactionCreditStatus::Voided,
        'status' => WalletTransactionStatus::Settled,
        'amount' => '4',
        'credit_amount' => '4',
        'remaining_amount_cents' => null,
    ]);

    $result = TrackConsumptionService::call(outboundWalletTransaction: $outbound);

    expect($result->success())->toBeTrue();

    $consumptions = WalletTransactionConsumption::query()->orderBy('inbound_wallet_transaction_id')->get();

    expect($consumptions->count())->toBe(2);

    $byGrant = $consumptions->keyBy('inbound_wallet_transaction_id');

    // Consumption order: priority, granted before purchased, created_at —
    // so the granted grant drains fully, then the older purchased one.
    expect($byGrant->get($granted->id)->consumed_amount_cents)->toBe(300)
        ->and($byGrant->get($oldPurchased->id)->consumed_amount_cents)->toBe(100)
        ->and($oldPurchased->refresh()->remaining_amount_cents)->toBe(100)
        ->and($granted->refresh()->remaining_amount_cents)->toBe(0)
        ->and($newerPurchased->refresh()->remaining_amount_cents)->toBe(500);
})->group('ledger:svc:WalletTransactions.TrackConsumptionService');

it('fails when the outbound amount exceeds the available inbound pool', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true]);

    grantFor($wallet, ['credit_amount' => '1', 'amount' => '1', 'remaining_amount_cents' => 100]);

    $outbound = WalletTransaction::factory()->forWallet($wallet)->create([
        'transaction_type' => WalletTransactionType::Outbound,
        'transaction_status' => WalletTransactionCreditStatus::Voided,
        'status' => WalletTransactionStatus::Settled,
        'amount' => '9',
        'credit_amount' => '9',
        'remaining_amount_cents' => null,
    ]);

    $result = TrackConsumptionService::call(outboundWalletTransaction: $outbound);

    expect($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['amount_cents' => ['exceeds_available_amount']]);
})->group('ledger:svc:WalletTransactions.TrackConsumptionService');
