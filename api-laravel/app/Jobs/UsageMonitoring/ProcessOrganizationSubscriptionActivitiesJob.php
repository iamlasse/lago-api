<?php

declare(strict_types=1);

namespace App\Jobs\UsageMonitoring;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\UsageMonitoring\ProcessOrganizationSubscriptionActivitiesService;

/**
 * Port of Rails' UsageMonitoring::ProcessOrganizationSubscriptionActivitiesJob
 * (app/jobs/usage_monitoring/process_organization_subscription_activities_job.rb).
 */
class ProcessOrganizationSubscriptionActivitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $organizationId)
    {
        $this->onQueue('alerts');
    }

    public function handle(): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        ProcessOrganizationSubscriptionActivitiesService::call(organization: $organization);
    }
}
