<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\DailyUsages\ComputeAllService;

/**
 * Port of Rails' Clock::ComputeAllDailyUsagesJob
 * (app/jobs/clock/compute_all_daily_usages_job.rb) — the hourly
 * `schedule:compute_daily_usage` clock entry (clock.rb, at: "*:15").
 */
class ComputeAllDailyUsagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 20 * 3600;
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    public function handle(): void
    {
        ComputeAllService::call(timestamp: now());
    }
}
