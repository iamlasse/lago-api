<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Wallets\CreateIntervalWalletTransactionsService;

/**
 * Port of Rails' Clock::CreateIntervalWalletTransactionsJob
 * (app/jobs/clock/create_interval_wallet_transactions_job.rb) — the hourly
 * sweep enqueueing the interval recurring rules' top-ups due today.
 */
class CreateIntervalWalletTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 4.hours`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        CreateIntervalWalletTransactionsService::call();
    }
}
