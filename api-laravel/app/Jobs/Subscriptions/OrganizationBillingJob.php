<?php

declare(strict_types=1);

namespace App\Jobs\Subscriptions;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Organizations\BillingService;

/**
 * Port of Rails' Subscriptions::OrganizationBillingJob
 * (app/jobs/subscriptions/organization_billing_job.rb).
 */
class OrganizationBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly Organization $organization)
    {
        // Rails queue: `clock_worker` when SIDEKIQ_CLOCK is set, `clock` otherwise.
        $this->onQueue('clock');
    }

    /** Port of `unique :until_executed, on_conflict: :log, lock_ttl: 12.hours`. */
    public function uniqueFor(): int
    {
        return 12 * 3600;
    }

    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\UniqueJob];
    }

    public function handle(): void
    {
        BillingService::call(
            organization: $this->organization,
            billingAt: now('UTC'),
        )->raiseIfError();
    }
}
