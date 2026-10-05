<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\UsageMonitoring\ProcessAllSubscriptionActivitiesService;

/**
 * Port of Rails' Clock::ProcessSubscriptionActivitiesJob family — the clock
 * entry fans out one ProcessOrganizationSubscriptionActivitiesJob per
 * organization with pending subscription activities (Rails' clock.rb entry
 * `ProcessAllSubscriptionActivitiesService.call` every 5 minutes).
 */
class ProcessAllSubscriptionActivitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        ProcessAllSubscriptionActivitiesService::call();
    }
}
