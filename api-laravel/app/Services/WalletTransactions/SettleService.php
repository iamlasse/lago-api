<?php

declare(strict_types=1);

namespace App\Services\WalletTransactions;

use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\WalletTransaction;
use App\Enums\WalletTransactionStatus;

/**
 * Port of Rails' WalletTransactions::SettleService
 * (app/services/wallet_transactions/settle_service.rb) — the
 * pending → settled transition once the paid top-up went through.
 */
class SettleService extends BaseService
{
    public function __construct(
        private readonly WalletTransaction $walletTransaction,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet_transaction');
        $walletTransaction = $this->walletTransaction;

        $walletTransaction->status = WalletTransactionStatus::Settled->value;
        $walletTransaction->settled_at = now();

        if ($walletTransaction->isInbound() && (bool) $walletTransaction->wallet->traceable) {
            $walletTransaction->remaining_amount_cents = $walletTransaction->amountCents();
        }

        $errors = $walletTransaction->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $walletTransaction->save();

        // Rails: SendWebhookJob.perform_later("wallet_transaction.updated", wallet_transaction)
        \App\Jobs\SendWebhookJob::performLater('wallet_transaction.updated', $walletTransaction);

        $result->wallet_transaction = $walletTransaction;

        return $result;
    }
}
