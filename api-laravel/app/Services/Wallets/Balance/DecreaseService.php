<?php

declare(strict_types=1);

namespace App\Services\Wallets\Balance;

use App\Models\Wallet;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;

/**
 * Port of Rails' Wallets::Balance::DecreaseService
 * (app/services/wallets/balance/decrease_service.rb).
 *
 * ProcessWalletAlertsJob — WIRED (the usage-monitoring slice; dispatched
 * after the balance save below).
 */
class DecreaseService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly WalletTransaction $walletTransaction,
        private readonly bool $skipRefresh = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet');
        $wallet = $this->wallet->fresh() ?? $this->wallet;
        $walletTransaction = $this->walletTransaction;

        $transactionCreditsAmount = (string) $walletTransaction->credit_amount;

        $wallet->balance_cents = (int) $wallet->balance_cents - $walletTransaction->amountCents();
        $wallet->credits_balance = MoneyMath::sub((string) $wallet->credits_balance, $transactionCreditsAmount, 5);
        $wallet->last_balance_sync_at = now();
        $wallet->consumed_credits = MoneyMath::add((string) $wallet->consumed_credits, $transactionCreditsAmount, 5);
        $wallet->consumed_amount_cents = (int) $wallet->consumed_amount_cents + $walletTransaction->amountCents();
        $wallet->last_consumed_credit_at = now();

        $errors = $wallet->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $wallet->save();

        if (! $this->skipRefresh) {
            $wallet->customer->flagWalletsForRefresh();

            // Rails: Customers::RefreshWalletJob.perform_after_commit(wallet.customer)
            dispatch(new \App\Jobs\Customers\RefreshWalletJob($wallet->customer));
        }

        // Rails: SendWebhookJob.perform_after_commit("wallet.updated", wallet)
        \App\Jobs\SendWebhookJob::performLater('wallet.updated', $wallet);

        // Rails: UsageMonitoring::ProcessWalletAlertsJob.perform_after_commit(wallet)
        // — the after-commit scheduling is not ported; dispatches immediately
        // (same as the other ported services).
        dispatch(new \App\Jobs\UsageMonitoring\ProcessWalletAlertsJob((string) $wallet->id));

        $result->wallet = $wallet;

        return $result;
    }
}
