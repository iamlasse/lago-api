<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Enums\BillingTime;
use App\Models\Plan as PlanModel;
use App\Services\Subscriptions\DatesService;
use App\Models\Subscription as SubscriptionModel;

/**
 * Field resolvers for the frozen SDL's `Subscription` type (port of Rails'
 * Types::Subscriptions::Object computed fields). Plain columns resolve
 * through the snake_case attribute fallback; unported features
 * (activation_rules, connections, fixed_charge unit overrides, usage
 * thresholds, lifetime usage, payment methods) keep the null fallback
 * documented in graphql/FULL_SCHEMA_NOTES.md.
 */
class Subscription
{
    /** Rails: the status enum name — the column stores the integer position. */
    public function status(SubscriptionModel $root): ?string
    {
        return $root->statusName();
    }

    /** Rails: the billing_time enum name — the column stores the integer position. */
    public function billingTime(SubscriptionModel $root): ?string
    {
        $raw = $root->getRawOriginal('billing_time');

        return $raw === null ? null : BillingTime::from((int) $raw)->label();
    }

    /** Rails: next_plan — next_subscription&.plan. */
    public function nextPlan(SubscriptionModel $root): ?PlanModel
    {
        return $root->nextSubscription()?->plan;
    }

    /** Rails: previous_plan — previous_subscription&.plan. */
    public function previousPlan(SubscriptionModel $root): ?PlanModel
    {
        return $root->previousSubscription?->plan;
    }

    /** Rails: next_name — next_subscription&.name. */
    public function nextName(SubscriptionModel $root): ?string
    {
        return $root->nextSubscription()?->name;
    }

    /** Rails: next_subscription_type — upgrade/downgrade from the plan amounts. */
    public function nextSubscriptionType(SubscriptionModel $root): ?string
    {
        if ($root->upgraded()) {
            return 'upgrade';
        }

        if ($root->downgraded()) {
            return 'downgrade';
        }

        return null;
    }

    /** Rails: next_subscription_at — next_subscription&.started_at || next_subscription&.subscription_at. */
    public function nextSubscriptionAt(SubscriptionModel $root): mixed
    {
        $next = $root->nextSubscription();

        return $next?->started_at ?? $next?->subscription_at;
    }

    /** Rails: downgrade_plan_date (Subscription#downgrade_plan_date). */
    public function downgradePlanDate(SubscriptionModel $root): mixed
    {
        return $root->downgradePlanDate();
    }

    /** Rails: period_end_date — DatesService#next_end_of_period. */
    public function periodEndDate(SubscriptionModel $root): mixed
    {
        return DatesService::newInstance($root, $root->billingReferenceTime())
            ->nextEndOfPeriod();
    }

    /** Rails: current_billing_period_started_at — the current-usage charges_from_datetime. */
    public function currentBillingPeriodStartedAt(SubscriptionModel $root): mixed
    {
        return $this->datesService($root)->chargesFromDatetime();
    }

    /** Rails: current_billing_period_ending_at — the current-usage charges_to_datetime. */
    public function currentBillingPeriodEndingAt(SubscriptionModel $root): mixed
    {
        return $this->datesService($root)->chargesToDatetime();
    }

    /**
     * Rails: lifetime_usage — nil unless has_progressive_billing? or the
     * organization enables lifetime usage.
     *
     * TODO(port): the LifetimeUsage model and the
     * organization.lifetime_usage_enabled? flag are not ported; the guard
     * keeps the Rails shape and resolves null meanwhile.
     */
    public function lifetimeUsage(SubscriptionModel $root): mixed
    {
        if (! $root->hasProgressiveBilling()) {
            return null;
        }

        return $root->lifetime_usage;
    }

    /**
     * Rails: the usage_thresholds field is a non-null list resolved from the
     * subscription's applicable thresholds.
     *
     * TODO(port): the UsageThreshold model is not ported;
     * Subscription#applicable_usage_thresholds returns [] meanwhile.
     *
     * @return list<mixed>
     */
    public function usageThresholds(SubscriptionModel $root): array
    {
        return $root->applicableUsageThresholds();
    }

    /** Rails: charges — the plan's charges, oldest first. */
    public function charges(SubscriptionModel $root): mixed
    {
        return $root->plan->charges()->orderBy('created_at')->get();
    }

    /** Rails: dates_service — the current-usage instance over the billing reference time. */
    private function datesService(SubscriptionModel $root): DatesService
    {
        return DatesService::newInstance($root, $root->billingReferenceTime(), true);
    }
}
