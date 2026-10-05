<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Models\Wallet;
use App\Services\BaseResult;

/**
 * Port of Rails' UsageMonitoring::ProcessWalletAlertsService
 * (app/services/usage_monitoring/process_wallet_alerts_service.rb) —
 * evaluates every wallet-scoped alert of a wallet against its live balances.
 */
class ProcessWalletAlertsService extends BaseService
{
    public function __construct(private readonly Wallet $wallet)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        if (! $this->wallet->alerts()->exists()) {
            return $result;
        }

        $alerts = $this->wallet->alerts()->usingWallet()->get();

        foreach ($alerts as $alert) {
            ProcessAlertService::call(
                alert: $alert,
                alertable: $this->wallet,
                currentMetrics: $this->wallet,
            );
        }

        return $result;
    }
}
