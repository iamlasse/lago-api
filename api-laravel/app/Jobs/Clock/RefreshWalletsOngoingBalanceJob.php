<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Customer;
use App\Support\License;
use Illuminate\Bus\Queueable;
use App\Jobs\Customers\RefreshWalletJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::RefreshWalletsOngoingBalanceJob
 * (app/jobs/clock/refresh_wallets_ongoing_balance_job.rb) — the periodic
 * sweep that hands every customer with an ongoing usage waiting to be
 * allocated to Customers::RefreshWalletJob.
 *
 * TODO(port): Utils::DedicatedWorkerConfig (dedicated org workers) — all
 * customers go to the default queue.
 */
class RefreshWalletsOngoingBalanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 3600;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        // Rails: `return unless License.premium?` — the ongoing balance is a
        // premium feature; the port treats a present license key as premium
        // (see BaseService#premium).
        if (! License::premium()) {
            return;
        }

        Customer::query()
            ->where('awaiting_wallet_refresh', true)
            ->whereHas('wallets', fn ($query) => $query->where('status', \App\Enums\WalletStatus::Active->value))
            ->chunkById(200, function ($customers): void {
                foreach ($customers as $customer) {
                    dispatch(new RefreshWalletJob($customer));
                }
            });
    }
}
