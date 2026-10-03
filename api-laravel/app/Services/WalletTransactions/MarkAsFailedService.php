<?php

declare(strict_types=1);

namespace App\Services\WalletTransactions;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;

/**
 * Port of Rails' WalletTransactions::MarkAsFailedService
 * (app/services/wallet_transactions/mark_as_failed_service.rb).
 */
class MarkAsFailedService extends BaseService
{
    public function __construct(
        private readonly ?WalletTransaction $walletTransaction,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet_transaction');
        $walletTransaction = $this->walletTransaction;

        if ($walletTransaction === null) {
            return $result;
        }

        if ($walletTransaction->isFailed()) {
            return $result;
        }

        // note: if a wallet transaction is settled, but they mark payment as
        // failed, they need to void credits manually
        if ($walletTransaction->isSettled()) {
            return $result;
        }

        $walletTransaction->markAsFailed();

        // Rails: SendWebhookJob.perform_later("wallet_transaction.updated", wallet_transaction)
        \App\Jobs\SendWebhookJob::performLater('wallet_transaction.updated', $walletTransaction);

        $result->wallet_transaction = $walletTransaction;

        return $result;
    }
}
