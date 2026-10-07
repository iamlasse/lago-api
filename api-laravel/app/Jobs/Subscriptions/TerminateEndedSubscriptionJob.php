<?php

declare(strict_types=1);

namespace App\Jobs\Subscriptions;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use App\Jobs\Middleware\UniqueJob;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\Subscriptions\TerminateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Port of Rails' Subscriptions::TerminateEndedSubscriptionJob
 * (app/jobs/subscriptions/terminate_ended_subscription_job.rb) — handles
 * async termination of ended subscriptions from
 * Clock::TerminateEndedSubscriptionsJob.
 *
 * Intentionally on the `default` queue: this job only triggers termination
 * which schedules billing separately — it doesn't perform billing itself,
 * so it shouldn't compete with billing jobs on the :billing queue.
 */
class TerminateEndedSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly Subscription $subscription,
    ) {
        $this->onQueue('default');
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
        TerminateService::callBang(
            subscription: Subscription::query()->find($this->subscription->id),
        );
    }
}
