<?php

declare(strict_types=1);

namespace App\Jobs\Clock;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\Subscriptions\OrganizationBillingJob;

/**
 * Port of Rails' Clock::SubscriptionsBillerJob
 * (app/jobs/clock/subscriptions_biller_job.rb) — runs hourly at :10 and
 * enqueues the per-organization biller.
 */
class SubscriptionsBillerJob implements ShouldQueue
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
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        Organization::query()->chunkById(200, function ($organizations): void {
            foreach ($organizations as $organization) {
                OrganizationBillingJob::dispatch(organization: $organization);
            }
        });
    }
}
