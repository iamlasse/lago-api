<?php

declare(strict_types=1);

namespace App\Services\Plans;

use App\Models\Plan;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Services\UsageThresholds\UpdateService;
use App\Jobs\LifetimeUsages\FlagRefreshFromPlanUpdateJob;

/**
 * Port of Rails' Plans::UpdateUsageThresholdsService
 * (app/services/plans/update_usage_thresholds_service.rb) — the full-form
 * usage_thresholds write on the plan update path (premium feature gate), with
 * the lifetime-usage refresh flag fanned out after commit.
 */
class UpdateUsageThresholdsService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Plan $plan,
        private readonly array $usageThresholdsParams,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('plan');
        $plan = $this->plan;

        $result->plan = $plan;

        if (! $plan->organization->progressiveBillingEnabled()) {
            return $result;
        }

        DB::transaction(function () use ($plan): void {
            UpdateService::callBang(
                model: $plan,
                usageThresholdsParams: $this->usageThresholdsParams,
                partial: false,
            );
        });

        $thresholdCount = $plan->usageThresholds()->count();

        if ($thresholdCount > 0) {
            FlagRefreshFromPlanUpdateJob::dispatch((string) $plan->id);
        }

        $result->plan = $plan->refresh();

        return $result;
    }
}
