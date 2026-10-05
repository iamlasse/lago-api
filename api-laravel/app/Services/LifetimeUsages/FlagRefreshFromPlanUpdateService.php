<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages;

use App\Models\Plan;
use App\Services\BaseResult;
use App\Models\LifetimeUsage;

/**
 * Port of Rails' LifetimeUsages::FlagRefreshFromPlanUpdateService
 * (app/services/lifetime_usages/flag_refresh_from_plan_update_service.rb) —
 * flags the invoiced-usage recalculation on the lifetime usage of every
 * active subscription of the updated plan.
 */
class FlagRefreshFromPlanUpdateService extends \App\Services\BaseService
{
    public function __construct(private readonly Plan $plan)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('updated_lifetime_usages');

        $subscriptionIds = $this->plan->subscriptions()->active()->select('id');

        $result->updated_lifetime_usages = LifetimeUsage::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->update(['recalculate_invoiced_usage' => true]);

        return $result;
    }
}
