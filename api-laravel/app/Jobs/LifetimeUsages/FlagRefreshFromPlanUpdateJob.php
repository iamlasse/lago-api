<?php

declare(strict_types=1);

namespace App\Jobs\LifetimeUsages;

use App\Models\Plan;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\LifetimeUsages\FlagRefreshFromPlanUpdateService;

/**
 * Port of Rails' LifetimeUsages::FlagRefreshFromPlanUpdateJob
 * (app/jobs/lifetime_usages/flag_refresh_from_plan_update_job.rb).
 */
class FlagRefreshFromPlanUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $planId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $plan = Plan::withTrashed()->find($this->planId);

        if ($plan === null) {
            return;
        }

        FlagRefreshFromPlanUpdateService::call(plan: $plan);
    }
}
