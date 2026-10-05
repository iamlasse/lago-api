<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Jobs\Middleware\UniqueJob;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Subscriptions\FreeTrialBillingService;

/**
 * Port of Rails' Clock::FreeTrialSubscriptionsBillerJob
 * (app/jobs/clock/free_trial_subscriptions_biller_job.rb) — the hourly pass
 * that bills and closes out active subscriptions whose free trial ended.
 *
 * clock.rb: every(1.hour, "schedule:bill_ended_trial_subscriptions", at: "*:35").
 */
class FreeTrialSubscriptionsBillerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue(filter_var(env('SIDEKIQ_BILLING'), FILTER_VALIDATE_BOOL) ? 'billing' : 'default');
    }

    public function middleware(): array
    {
        return [new UniqueJob];
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 4.hours`. */
    public function uniqueFor(): int
    {
        return 4 * 3600;
    }

    public function handle(): void
    {
        FreeTrialBillingService::call(timestamp: now());
    }
}
