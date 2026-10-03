<?php

declare(strict_types=1);

namespace App\Services\Wallets;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Wallets::TerminateService
 * (app/services/wallets/terminate_service.rb).
 *
 * TODO(port): Rails terminates the wallet's recurring_transaction_rules in
 * the same transaction (RecurringTransactionRules::TerminateService) — no
 * RecurringTransactionRule model yet.
 */
class TerminateService extends BaseService
{
    public function __construct(
        private readonly ?Wallet $wallet,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet');
        $wallet = $this->wallet;

        if ($wallet === null) {
            return $result->notFoundFailure('wallet');
        }

        if (! $wallet->isTerminated()) {
            DB::transaction(function () use ($wallet): void {
                $wallet->markAsTerminated();

                // TODO(port): terminate the wallet's recurring_transaction_rules
                // (RecurringTransactionRules::TerminateService).

                $wallet->customer->flagWalletsForRefresh();

                // Rails: SendWebhookJob.perform_after_commit("wallet.terminated", wallet)
                \App\Jobs\SendWebhookJob::performLater('wallet.terminated', $wallet);
            });
        }

        $result->wallet = $wallet;

        return $result;
    }
}
