<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use Carbon\CarbonInterface;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Models\UsageMonitoring\Alert;
use App\Models\UsageMonitoring\SubscriptionActivity;

/**
 * Port of Rails' UsageMonitoring::TrackSubscriptionActivityService
 * (app/services/usage_monitoring/track_subscription_activity_service.rb) —
 * called from the events post-process: stamps the subscription's
 * last_received_event_on and enqueues a (deduplicated) subscription activity
 * when the org's premium features need periodic usage processing.
 */
class TrackSubscriptionActivityService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly CarbonInterface $date,
        private readonly ?Organization $organization = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();

        if (! $this->premium()) {
            return $result;
        }

        $subscription = $this->subscription;

        if (! $subscription->active()) {
            return $result;
        }

        if ($subscription->last_received_event_on?->toDateString() !== $this->date->toDateString()) {
            $subscription->last_received_event_on = $this->date;
            $subscription->save();
        }

        if (! $this->needLifetimeUsage() && ! $this->hasAlerts()) {
            return $result;
        }

        SubscriptionActivity::insertFor($subscription, (string) $this->organization()->id);

        return $result;
    }

    private function organization(): Organization
    {
        return $this->organization ?? $this->subscription->organization;
    }

    private function needLifetimeUsage(): bool
    {
        $organization = $this->organization();

        if ($organization->lifetimeUsageEnabled()) {
            return true;
        }

        return $organization->progressiveBillingEnabled()
            && $this->subscription->hasProgressiveBilling();
    }

    private function hasAlerts(): bool
    {
        return Alert::query()
            ->where('subscription_external_id', $this->subscription->external_id)
            ->exists();
    }
}
