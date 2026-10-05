<?php

declare(strict_types=1);

namespace App\Jobs\UsageMonitoring;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Services\UsageMonitoring\ProcessSubscriptionActivityService;

/**
 * Port of Rails' UsageMonitoring::ProcessSubscriptionActivityJob
 * (app/jobs/usage_monitoring/process_subscription_activity_job.rb).
 */
class ProcessSubscriptionActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int|string $subscriptionActivityId)
    {
        $this->onQueue('alerts');
    }

    public function handle(): void
    {
        $subscriptionActivity = SubscriptionActivity::query()->find($this->subscriptionActivityId);

        if ($subscriptionActivity === null) {
            return;
        }

        ProcessSubscriptionActivityService::call(subscriptionActivity: $subscriptionActivity);
    }
}
