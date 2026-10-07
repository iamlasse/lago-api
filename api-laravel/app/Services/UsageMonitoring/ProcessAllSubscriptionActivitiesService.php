<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Services\BaseResult;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Jobs\UsageMonitoring\ProcessOrganizationSubscriptionActivitiesJob;

/**
 * Port of Rails' UsageMonitoring::ProcessAllSubscriptionActivitiesService
 * (app/services/usage_monitoring/process_all_subscription_activities_service.rb).
 *
 * NOTE (Rails): If we need to handle different delays per organization, this
 * would be done here. This is also where we should report metrics — that's
 * why it's a dedicated service and not just done in the job.
 *
 * TODO(port): the DedicatedWorkerConfig org split (Rails' dedicated workers)
 * has no equivalent here; every pending organization goes through the same
 * queue.
 */
class ProcessAllSubscriptionActivitiesService extends BaseService
{
    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        SubscriptionActivity::query()
            ->where('enqueued', false)
            ->distinct()
            ->pluck('organization_id')
            ->each(function (string $organizationId): void {
                dispatch(new \App\Jobs\UsageMonitoring\ProcessOrganizationSubscriptionActivitiesJob($organizationId));
            });

        return $result;
    }
}
