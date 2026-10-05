<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Plan as PlanModel;

/**
 * Field resolvers for the frozen SDL's `Plan` type — the usage-monitoring
 * slice adds usageThresholds / applicableUsageThresholds (port of Rails'
 * Types::Plan threshold fields; plain columns resolve through Lighthouse's
 * default snake_case attribute lookup).
 */
class Plan
{
    /**
     * Rails: plan.usage_thresholds — the kept thresholds of this plan.
     */
    public function usageThresholds(PlanModel $root): array
    {
        return $root->usageThresholds->all();
    }

    /**
     * Rails: plan.applicable_usage_thresholds — the override parent's when
     * this plan is an override child.
     */
    public function applicableUsageThresholds(PlanModel $root): array
    {
        return $root->applicableUsageThresholds()->all();
    }
}
