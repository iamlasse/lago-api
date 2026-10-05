<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Models\Organization;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Jobs\UsageMonitoring\ProcessSubscriptionActivityJob;

/**
 * Port of Rails' UsageMonitoring::ProcessOrganizationSubscriptionActivitiesService
 * (app/services/usage_monitoring/process_organization_subscription_activities_service.rb)
 * — batches the organization's pending activities, flags them enqueued and
 * fans out one job per row (Rails: ApplicationJob.perform_all_later).
 */
class ProcessOrganizationSubscriptionActivitiesService extends BaseService
{
    public const BATCH_SIZE = 500;

    public function __construct(private readonly Organization $organization)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('nb_jobs_enqueued');
        $nbJobsEnqueued = 0;

        $this->organization->subscriptionActivities()
            ->where('enqueued', false)
            ->select('id')
            ->chunkById(self::BATCH_SIZE, function ($batch) use (&$nbJobsEnqueued): void {
                DB::transaction(function () use ($batch, &$nbJobsEnqueued): void {
                    $ids = $batch->pluck('id')->all();

                    SubscriptionActivity::query()
                        ->whereIn('id', $ids)
                        ->update(['enqueued' => true, 'enqueued_at' => now()]);

                    foreach ($ids as $id) {
                        ProcessSubscriptionActivityJob::dispatch($id);
                    }
                });

                $nbJobsEnqueued += count($batch);
            });

        $result->nb_jobs_enqueued = $nbJobsEnqueued;

        return $result;
    }
}
