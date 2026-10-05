<?php

declare(strict_types=1);

namespace App\Jobs\UsageMonitoring;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\UsageMonitoring\ProcessLifetimeUsageAlertService;

/**
 * Port of Rails' UsageMonitoring::ProcessLifetimeUsageAlertJob
 * (app/jobs/usage_monitoring/process_lifetime_usage_alert_job.rb) — the
 * deferred (per-metric) evaluation of billable_metric_lifetime_usage_units
 * alerts.
 */
class ProcessLifetimeUsageAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $alertId,
        public readonly string $subscriptionId,
    ) {
        $this->onQueue('alerts');
    }

    public function handle(): void
    {
        $alert = Alert::withTrashed()->find($this->alertId);
        $subscription = Subscription::query()->find($this->subscriptionId);

        if ($alert === null || $subscription === null) {
            return;
        }

        ProcessLifetimeUsageAlertService::call(alert: $alert, subscription: $subscription);
    }
}
