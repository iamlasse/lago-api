<?php

declare(strict_types=1);

namespace App\Services\Subscriptions\Concerns;

use App\Models\Plan;
use App\Services\Plans\OverrideService;

/**
 * Port of Rails' Subscriptions::Concerns::PlanOverrideConcern
 * (app/services/subscriptions/concerns/plan_override_concern.rb).
 *
 * The consuming service must expose `$this->subscription`.
 */
trait PlanOverrideConcern
{
    /**
     * Returns the plan the negotiated writes target: the already-overridden
     * plan when the subscription carries one, otherwise a fresh override
     * (clone) the subscription is switched to.
     */
    protected function ensurePlanOverride(array $params = []): Plan
    {
        $currentPlan = $this->subscription->plan;

        if ($currentPlan->parent_id !== null) {
            return $currentPlan;
        }

        $overrideResult = OverrideService::callBang(
            plan: $currentPlan,
            params: $params,
            subscription: $this->subscription,
        );

        $this->subscription->plan_id = $overrideResult->plan->id;
        $this->subscription->save();

        return $overrideResult->plan;
    }
}
