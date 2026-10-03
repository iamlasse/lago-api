<?php

declare(strict_types=1);

require_once __DIR__.'/../Wallets/WalletsTestHelpers.php';

use App\Models\Wallet;
use App\Support\CurrentContext;
use App\Enums\WalletTransactionType;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionCreditStatus;
use App\Services\Failures\ValidationFailure;
use App\Services\WalletTransactions\CreateFromParamsService;

beforeEach(function (): void {
    CurrentContext::reset();
});

function walletTopUpParams(Wallet $wallet, array $overrides = []): array
{
    return array_merge([
        'wallet_id' => $wallet->id,
        'granted_credits' => '10',
        'name' => 'Top up',
    ], $overrides);
}

it('creates a settled granted transaction and increases the balance', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => true]);

    $result = CreateFromParamsService::call(
        organization: $organization,
        params: walletTopUpParams($wallet),
    );

    expect($result->success())->toBeTrue();

    $transactions = $result->wallet_transactions;

    expect(count($transactions))->toBe(1);

    $transaction = $transactions[0];

    expect($transaction->transactionTypeEnum())->toBe(WalletTransactionType::Inbound)
        ->and($transaction->statusEnum())->toBe(WalletTransactionStatus::Settled)
        ->and($transaction->transactionStatusEnum())->toBe(WalletTransactionCreditStatus::Granted)
        ->and($transaction->credit_amount)->toBe('10.00000')
        ->and($transaction->amount)->toBe('10.00000')
        ->and($transaction->name)->toBe('Top up')
        ->and($transaction->priority)->toBe(50)
        ->and($transaction->sourceEnum()?->label())->toBe('manual')
        ->and($transaction->settled_at)->not->toBeNull()
        // Traceable wallet: the grant starts with a full remaining ledger.
        ->and($transaction->remaining_amount_cents)->toBe(1000)
        ->and($transaction->billing_entity_id)->toBe($wallet->resolvedBillingEntity()?->id);

    $wallet->refresh();

    expect($wallet->balance_cents)->toBe(1000)
        ->and($wallet->credits_balance)->toBe('10.00000');
})->group('ledger:svc:WalletTransactions.CreateFromParamsService');

it('creates a pending purchased transaction for paid credits', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => false]);

    $result = CreateFromParamsService::call(
        organization: $organization,
        params: walletTopUpParams($wallet, [
            'granted_credits' => null,
            'paid_credits' => '25',
            'invoice_requires_successful_payment' => true,
        ]),
    );

    expect($result->success())->toBeTrue();

    $transaction = $result->wallet_transactions[0];

    expect($transaction->statusEnum())->toBe(WalletTransactionStatus::Pending)
        ->and($transaction->transactionStatusEnum())->toBe(WalletTransactionCreditStatus::Purchased)
        ->and($transaction->credit_amount)->toBe('25.00000')
        ->and((bool) $transaction->invoice_requires_successful_payment)->toBeTrue()
        // Non-traceable wallet: no consumption ledger.
        ->and($transaction->remaining_amount_cents)->toBeNull();

    $wallet->refresh();

    // Paid credits only settle (and move the balance) once the payment goes
    // through — the wallet balance must be untouched.
    expect($wallet->balance_cents)->toBe(0)
        ->and($wallet->credits_balance)->toBe('0.00000');
})->group('ledger:svc:WalletTransactions.CreateFromParamsService');

it('rejects paid credits above the wallet top-up limits', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, [
        'traceable' => false,
        'rate_amount' => '1',
        'paid_top_up_max_amount_cents' => 5000,
    ]);

    $result = CreateFromParamsService::call(
        organization: $organization,
        params: walletTopUpParams($wallet, [
            'granted_credits' => null,
            'paid_credits' => '100',
        ]),
    );

    expect($result->getError())->toBeInstanceOf(ValidationFailure::class)
        ->and($result->getError()->messages)->toBe(['paid_credits' => ['amount_above_maximum']]);

    // ignore_paid_top_up_limits skips the bounds check.
    $ignored = CreateFromParamsService::call(
        organization: $organization,
        params: walletTopUpParams($wallet, [
            'granted_credits' => null,
            'paid_credits' => '100',
            'ignore_paid_top_up_limits' => true,
        ]),
    );

    expect($ignored->success())->toBeTrue();
})->group('ledger:svc:WalletTransactions.CreateFromParamsService');

it('validates the wallet, amounts, name and metadata', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer);

    $unknown = CreateFromParamsService::call(organization: $organization, params: ['wallet_id' => '00000000-0000-0000-0000-000000000000', 'granted_credits' => '1']);
    expect($unknown->getError()->messages)->toBe(['wallet_id' => ['wallet_not_found']]);

    $terminated = walletFor($customer);
    $terminated->markAsTerminated();

    $terminatedResult = CreateFromParamsService::call(organization: $organization, params: walletTopUpParams($terminated));
    expect($terminatedResult->getError()->messages)->toBe(['wallet_id' => ['wallet_is_terminated']]);

    $invalidAmount = CreateFromParamsService::call(organization: $organization, params: walletTopUpParams($wallet, ['granted_credits' => '-3']));
    expect($invalidAmount->getError()->messages)->toBe(['granted_credits' => ['invalid_granted_credits', 'invalid_amount']]);

    $tooLongName = CreateFromParamsService::call(organization: $organization, params: walletTopUpParams($wallet, ['name' => str_repeat('x', 256)]));
    expect($tooLongName->getError()->messages)->toBe(['name' => ['too_long']]);

    $badMetadata = CreateFromParamsService::call(organization: $organization, params: walletTopUpParams($wallet, ['metadata' => [['nope' => 'x']]]));
    expect($badMetadata->getError()->messages)->toBe(['metadata' => ['invalid_key_value_pair']]);
})->group('ledger:svc:WalletTransactions.CreateFromParamsService');

it('rejects voided credits beyond the balance and untraceable per-grant voids', function (): void {
    [$organization, $customer] = walletSetup();
    $wallet = walletFor($customer, ['traceable' => false, 'balance_cents' => 0, 'credits_balance' => '0']);

    $insufficient = CreateFromParamsService::call(
        organization: $organization,
        params: walletTopUpParams($wallet, ['granted_credits' => null, 'voided_credits' => '5']),
    );

    expect($insufficient->getError()->messages)->toBe(['voided_credits' => ['insufficient_credits']]);

    // Per-grant voids need a traceable wallet.
    $untraceable = CreateFromParamsService::call(
        organization: $organization,
        params: walletTopUpParams($wallet, [
            'granted_credits' => null,
            'voided_transaction_id' => '00000000-0000-0000-0000-000000000000',
        ]),
    );

    expect($untraceable->getError()->messages)->toBe(['voided_transaction_id' => ['wallet_not_traceable']]);
})->group('ledger:svc:WalletTransactions.CreateFromParamsService');
