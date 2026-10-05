<?php

declare(strict_types=1);

namespace App\Jobs\UsageMonitoring;

use App\Models\Wallet;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\UsageMonitoring\ProcessWalletAlertsService;

/**
 * Port of Rails' UsageMonitoring::ProcessWalletAlertsJob
 * (app/jobs/usage_monitoring/process_wallet_alerts_job.rb).
 */
class ProcessWalletAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $walletId)
    {
        $this->onQueue('alerts');
    }

    public function handle(): void
    {
        $wallet = Wallet::query()->find($this->walletId);

        if ($wallet === null) {
            return;
        }

        ProcessWalletAlertsService::call(wallet: $wallet);
    }
}
