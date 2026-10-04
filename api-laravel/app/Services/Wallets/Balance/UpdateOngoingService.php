<?php

declare(strict_types=1);

namespace App\Services\Wallets\Balance;

use App\Models\Wallet;
use App\Services\BaseResult;
use App\Services\BaseService;

/**
 * Port of Rails' Wallets::Balance::UpdateOngoingService
 * (app/services/wallets/balance/update_ongoing_service.rb).
 *
 * TODO(port): the after_commit block also enqueues ThresholdTopUpService
 * (RecurringTransactionRule — no model yet) and
 * UsageMonitoring::ProcessWalletAlertsJob — later slices.
 */
class UpdateOngoingService extends BaseService
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly array $updateParams,
        private readonly bool $skipSingleWalletUpdate = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('wallet');
        $wallet = $this->wallet;
        $updateParams = $this->updateParams;

        if (! $this->skipSingleWalletUpdate) {
            $updateParams['last_ongoing_balance_sync_at'] = now();
        }

        foreach ($updateParams as $attribute => $value) {
            $wallet->{$attribute} = $value;
        }

        $errors = $wallet->validateAttributes();

        if ($errors !== []) {
            return $result->recordValidationFailure($errors);
        }

        $wallet->save();

        $stateChanged = array_key_exists('ongoing_usage_balance_cents', $wallet->getChanges())
            || array_key_exists('ongoing_balance_cents', $wallet->getChanges());

        if (($updateParams['depleted_ongoing_balance'] ?? null) === true) {
            // Rails: SendWebhookJob.perform_later("wallet.depleted_ongoing_balance", wallet)
            \App\Jobs\SendWebhookJob::performLater('wallet.depleted_ongoing_balance', $wallet);
        }

        $result->wallet = $wallet;

        return $result;
    }
}
