<?php

declare(strict_types=1);

require_once __DIR__.'/../WalletsTestHelpers.php';

use App\Models\Fee;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionCreditStatus;
use App\Services\Wallets\Balance\DecreaseService;
use App\Services\Wallets\Balance\IncreaseService;
use App\Services\Wallets\Balance\RefreshOngoingUsageService;
use App\Services\Wallets\Balance\AllocateOngoingUsageByWalletsService;

function walletTransactionFor(Wallet $wallet, array $overrides = []): WalletTransaction
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

it('increases the balance by the transaction amounts and resets consumed credits', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, [
        'balance_cents' => 100,
        'credits_balance' => '1.00000',
        'consumed_credits' => '2.00000',
        'consumed_amount_cents' => 200,
    ]);
    $transaction = walletTransactionFor($wallet, ['credit_amount' => '10', 'amount' => '10']);

    $result = IncreaseService::call(wallet: $wallet, walletTransaction: $transaction, resetConsumedCredits: true);

    expect($result->success())->toBeTrue();

    $wallet->refresh();

    expect($wallet->balance_cents)->toBe(1100)
        ->and($wallet->credits_balance)->toBe('11.00000')
        ->and($wallet->consumed_credits)->toBe('0.00000')
        ->and($wallet->consumed_amount_cents)->toBe(0)
        ->and($wallet->last_balance_sync_at)->not->toBeNull()
        ->and($customer->refresh()->awaiting_wallet_refresh)->toBeTrue();
})->group('ledger:svc:Wallets.Balance.IncreaseService');

it('decreases the balance and accumulates consumed credits', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, [
        'balance_cents' => 1000,
        'credits_balance' => '10.00000',
    ]);
    $transaction = walletTransactionFor($wallet, [
        'transaction_type' => WalletTransactionType::Outbound,
        'transaction_status' => WalletTransactionCreditStatus::Voided,
        'amount' => '4',
        'credit_amount' => '4',
        'remaining_amount_cents' => null,
    ]);

    $result = DecreaseService::call(wallet: $wallet, walletTransaction: $transaction);

    expect($result->success())->toBeTrue();

    $wallet->refresh();

    expect($wallet->balance_cents)->toBe(600)
        ->and($wallet->credits_balance)->toBe('6.00000')
        ->and($wallet->consumed_credits)->toBe('4.00000')
        ->and($wallet->consumed_amount_cents)->toBe(400)
        ->and($wallet->last_consumed_credit_at)->not->toBeNull();
})->group('ledger:svc:Wallets.Balance.DecreaseService');

it('refreshes the ongoing usage and toggles the depleted flag', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['balance_cents' => 1000, 'rate_amount' => '2']);

    $result = RefreshOngoingUsageService::call(wallet: $wallet, ongoingUsageAmountCents: 400);

    expect($result->success())->toBeTrue();

    $wallet->refresh();

    expect($wallet->ongoing_usage_balance_cents)->toBe(400)
        ->and($wallet->ongoing_balance_cents)->toBe(600)
        // 600 cents / 100 (subunit) / 2 (rate) = 3 credits.
        ->and($wallet->credits_ongoing_balance)->toBe('3.00000')
        // 400 / 100 / 2 = 2 credits.
        ->and($wallet->credits_ongoing_usage_balance)->toBe('2.00000')
        ->and($wallet->last_ongoing_balance_sync_at)->not->toBeNull();

    // The whole balance is pending usage: the wallet goes depleted.
    RefreshOngoingUsageService::call(wallet: $wallet, ongoingUsageAmountCents: 1000);
    expect($wallet->refresh()->depleted_ongoing_balance)->toBeTrue()
        ->and($wallet->ongoing_balance_cents)->toBe(0);

    // Usage drops back: the flag clears.
    RefreshOngoingUsageService::call(wallet: $wallet, ongoingUsageAmountCents: 100);
    expect($wallet->refresh()->depleted_ongoing_balance)->toBeFalse();
})->group('ledger:svc:Wallets.Balance.RefreshOngoingUsageService');

it('allocates ongoing usage across wallets in priority order with a last-wallet overflow', function (): void {
    [$organization, $customer] = walletSetup();

    $low = walletFor($customer, ['balance_cents' => 100, 'priority' => 10]);
    $high = walletFor($customer, ['balance_cents' => 100, 'priority' => 50]);

    $fee = Fee::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 350,
        'taxes_amount_cents' => 0,
        'amount_currency' => 'EUR',
    ]);

    $result = AllocateOngoingUsageByWalletsService::call(
        customer: $customer,
        wallets: [$low, $high],
        currentUsageFees: [$fee],
        draftInvoicesFees: [],
        progressiveBillingFees: [],
        payInAdvanceFees: [],
    );

    expect($result->success())->toBeTrue();

    $allocations = $result->wallet_allocations;

    // The low-priority wallet caps at its balance; the last applicable one
    // absorbs the overflow and may go negative.
    expect($allocations[$low->id])->toBe(100)
        ->and($allocations[$high->id])->toBe(250);
})->group('ledger:svc:Wallets.Balance.AllocateOngoingUsageByWalletsService');

it('restricts allocations to the wallet targets', function (): void {
    [$organization, $customer] = walletSetup();

    $metric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);
    $otherMetric = App\Models\BillableMetric::factory()->create(['organization_id' => $organization->id]);

    $restricted = walletFor($customer, ['balance_cents' => 100, 'priority' => 10]);
    $restricted->walletTargets()->create(['billable_metric_id' => $metric->id, 'organization_id' => $organization->id]);

    $unrestricted = walletFor($customer, ['balance_cents' => 500, 'priority' => 50]);

    $targetedFee = Fee::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 200,
        'taxes_amount_cents' => 0,
        'amount_currency' => 'EUR',
        // charge fees keyed on the charge's billable metric
        'fee_type' => App\Enums\FeeType::Charge,
        'charge_id' => App\Models\Charge::factory()->create([
            'organization_id' => $organization->id,
            'plan_id' => App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'interval' => App\Enums\PlanInterval::Monthly]),
            'billable_metric_id' => $metric->id,
        ])->id,
    ]);

    $otherFee = Fee::factory()->create([
        'organization_id' => $organization->id,
        'amount_cents' => 100,
        'taxes_amount_cents' => 0,
        'amount_currency' => 'EUR',
        'fee_type' => App\Enums\FeeType::Charge,
        'charge_id' => App\Models\Charge::factory()->create([
            'organization_id' => $organization->id,
            'plan_id' => App\Models\Plan::factory()->create(['organization_id' => $organization->id, 'interval' => App\Enums\PlanInterval::Monthly]),
            'billable_metric_id' => $otherMetric->id,
        ])->id,
    ]);

    $result = AllocateOngoingUsageByWalletsService::call(
        customer: $customer,
        wallets: [$restricted, $unrestricted],
        currentUsageFees: [$targetedFee, $otherFee],
        draftInvoicesFees: [],
        progressiveBillingFees: [],
        payInAdvanceFees: [],
    );

    $allocations = $result->wallet_allocations;

    // The targeted fee (200) is applicable to both: the restricted wallet
    // caps at its 100 balance, the unrestricted one takes the rest; the
    // other fee (100) is only applicable to the unrestricted wallet.
    expect($allocations[$restricted->id])->toBe(100)
        ->and($allocations[$unrestricted->id])->toBe(200);
})->group('ledger:svc:Wallets.Balance.AllocateOngoingUsageByWalletsService');
