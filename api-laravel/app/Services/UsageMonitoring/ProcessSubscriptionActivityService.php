<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use Throwable;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Models\LifetimeUsage;
use App\Models\UsageMonitoring\Alert;
use App\Services\Invoices\CustomerUsageService;
use App\Jobs\LifetimeUsages\RecalculateAndCheckJob;
use App\Models\UsageMonitoring\SubscriptionActivity;
use App\Jobs\UsageMonitoring\ProcessLifetimeUsageAlertJob;

/**
 * Port of Rails' UsageMonitoring::ProcessSubscriptionActivityService
 * (app/services/usage_monitoring/process_subscription_activity_service.rb).
 *
 * NOTE (Rails): We would typically have one job for progressive billing and
 * one job for Alerting but in order to reduce calls to `current_usage`, we do
 * both in the same job. A rescue is added to ensure we process alerts even if
 * progressive billing breaks. The subscription_activity is deleted if
 * something raises.
 */
class ProcessSubscriptionActivityService extends BaseService
{
    public function __construct(private readonly SubscriptionActivity $subscriptionActivity)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();
        $subscription = $this->subscriptionActivity->subscription;

        if (! $subscription->active()) {
            $this->subscriptionActivity->delete();

            return $result;
        }

        $exceptionToRaise = null;
        $lifetimeUsage = $this->findOrCreateLifetimeUsage($subscription);

        // Note we rely on the jobs rather than on the services to take advantage
        // of the job's uniqueness strategy.
        try {
            if ($this->organization($subscription)->usingLifetimeUsage()) {
                dispatch_sync(new \App\Jobs\LifetimeUsages\RecalculateAndCheckJob($lifetimeUsage, currentUsage: null));
            }
        } catch (Throwable $e) {
            $exceptionToRaise = $e;
        }

        $alerts = Alert::query()
            ->where('subscription_external_id', $subscription->external_id)
            ->where('organization_id', $this->subscriptionActivity->organization_id)
            ->get();

        foreach ($alerts as $alert) {
            try {
                match ((string) $alert->alert_type) {
                    'lifetime_usage_amount' => ProcessAlertService::call(
                        alert: $alert,
                        alertable: $subscription,
                        currentMetrics: $lifetimeUsage,
                    ),
                    Alert::BILLABLE_METRIC_LIFETIME_USAGE_TYPES[0] => dispatch(new \App\Jobs\UsageMonitoring\ProcessLifetimeUsageAlertJob(alertId: $alert->id, subscriptionId: $subscription->id))->delay($this->processingInterval()),
                    default => ProcessAlertService::call(
                        alert: $alert,
                        alertable: $subscription,
                        currentMetrics: $this->currentUsage($subscription),
                    ),
                };
            } catch (Throwable $e) {
                $exceptionToRaise ??= $e;
            }
        }

        $this->subscriptionActivity->delete();

        if ($exceptionToRaise !== null) {
            throw $exceptionToRaise;
        }

        return $result;
    }

    private function organization(Subscription $subscription): \App\Models\Organization
    {
        return $subscription->organization;
    }

    private function processingInterval(): int
    {
        $interval = env('LAGO_SUBSCRIPTION_ACTIVITY_PROCESSING_INTERVAL_SECONDS');

        return (int) ($interval ?: 60);
    }

    private function currentUsage(Subscription $subscription): \App\Support\SubscriptionUsage
    {
        /** @var BaseResult $usageResult */
        $usageResult = CustomerUsageService::call(
            customer: $subscription->customer,
            subscription: $subscription,
            applyTaxes: false, // Never use taxes for alerting
            withCache: true,
        );

        return $usageResult->raiseIfError()->usage;
    }

    private function findOrCreateLifetimeUsage(Subscription $subscription): LifetimeUsage
    {
        $lifetimeUsage = $subscription->lifetimeUsage;

        $lifetimeUsage ??= $subscription->createLifetimeUsage();

        return $lifetimeUsage;
    }
}
