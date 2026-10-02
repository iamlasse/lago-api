<?php

declare(strict_types=1);

namespace App\Jobs\Subscriptions;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Subscriptions\TerminateService;

/**
 * Port of Rails' Subscriptions::TerminateJob
 * (app/jobs/subscriptions/terminate_job.rb) — deferred termination used by
 * the biller when a downgrade's pending subscription is due.
 */
class TerminateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $timestamp,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        TerminateService::call(
            subscription: Subscription::query()->find($this->subscription->id),
        );
    }
}
