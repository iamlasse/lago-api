<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\Subscriptions\ActivateAllPendingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Clock::ActivateSubscriptionsJob
 * (app/jobs/clock/activate_subscriptions_job.rb) — the every-5-minutes
 * sweep that activates pending subscriptions whose subscription_at has come
 * due (in the customer's timezone).
 */
class ActivateSubscriptionsJob implements ShouldQueue
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
        ActivateAllPendingService::callBang(
            timestamp: now()->getTimestamp(),
        );
    }
}
