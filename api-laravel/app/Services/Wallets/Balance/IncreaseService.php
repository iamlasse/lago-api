<?php

declare(strict_types=1);

namespace App\Services\Wallets\Balance;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;

/**
 * Port of Rails' Wallets::Balance::IncreaseService
 * (app/services/wallets/balance/increase_service.rb).
 *
 * TODO(port): UsageMonitoring::ProcessWalletAlertsJob after commit — the
 * usage-monitoring slice.
 */
class IncreaseService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly WalletTransaction $walletTransaction,
        private readonly bool $resetConsumedCredits = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet');
        $wallet = $this->wallet;
        $walletTransaction = $this->walletTransaction;

        $transactionCreditsAmount = (string) $walletTransaction->credit_amount;
        $transactionAmountCents = $walletTransaction->amountCents();

        $currency = $wallet->currencyForBalance();

        $wallet->balance_cents = (int) $wallet->balance_cents + $transactionAmountCents;
        $wallet->credits_balance = \App\Support\MoneyMath::add((string) $wallet->credits_balance, $transactionCreditsAmount, 5);
        $wallet->last_balance_sync_at = now();

        if ($this->resetConsumedCredits) {
            $remainingConsumedCredits = \App\Support\MoneyMath::sub((string) $wallet->consumed_credits, $transactionCreditsAmount, 5);

            // [0.0, consumed_credits - transaction_credits].max
            $wallet->consumed_credits = bccomp($remainingConsumedCredits, '0', 5) === 1
                ? $remainingConsumedCredits
                : '0.00000';

            // ((consumed_credits - transaction_credits) * rate_amount *
            // subunit_to_unit).floor, computed from the pre-update values.
            $remainingConsumedAmount = \App\Support\MoneyMath::floor(
                \App\Support\MoneyMath::mul(
                    \App\Support\MoneyMath::mul($remainingConsumedCredits, (string) $wallet->rate_amount),
                    (string) $currency->subunit_to_unit,
                ),
            );

            // [0, ...].max
            $wallet->consumed_amount_cents = max(0, $remainingConsumedAmount);
        }

        $errors = $wallet->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $wallet->save();

        // we only need to update all wallets when there is usage applied.
        $wallet->customer->flagWalletsForRefresh();

        // Rails: Customers::RefreshWalletJob.perform_after_commit(customer,
        // wallet_ids: [wallet.id]) — the explicit wallet_ids marks the
        // requested refresh and bypasses the customer-wide flag.
        \App\Jobs\Customers\RefreshWalletJob::dispatch($wallet->customer, [(string) $wallet->id]);

        // Rails: SendWebhookJob.perform_after_commit("wallet.updated", wallet)
        \App\Jobs\SendWebhookJob::performLater('wallet.updated', $wallet);

        $result->wallet = $wallet;

        return $result;
    }
}
