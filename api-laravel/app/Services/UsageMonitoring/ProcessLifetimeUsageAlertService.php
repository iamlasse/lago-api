<?php

declare(strict_types=1);

namespace App\Services\UsageMonitoring;

use App\Models\Subscription;
use App\Services\BaseResult;
use App\Models\UsageMonitoring\Alert;
use App\Services\Invoices\CustomerUsageService;

/**
 * Port of Rails' UsageMonitoring::ProcessLifetimeUsageAlertService
 * (app/services/usage_monitoring/process_lifetime_usage_alert_service.rb) —
 * evaluates a billable_metric_lifetime_usage_units alert against the usage
 * of only that metric's charges.
 *
 * TODO(port): Rails narrows the computation itself with UsageFilters
 * (full_usage: true, filter_by_charge_id:) — the usage-filters pipeline is a
 * later slice; the port computes the subscription's full current usage and
 * the alert's find_value picks this metric's fees out of it (same outcome,
 * more work per evaluation).
 */
class ProcessLifetimeUsageAlertService extends BaseService
{
    public function __construct(
        private readonly Alert $alert,
        private readonly ?Subscription $subscription = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();
        $subscription = $this->subscription;

        if ((string) $this->alert->alert_type !== 'billable_metric_lifetime_usage_units') {
            return $result;
        }

        if ($subscription === null || ! $subscription->active()) {
            return $result;
        }

        $billableMetricId = (string) $this->alert->billable_metric_id;

        $chargeIds = $subscription->plan->charges()
            ->where('billable_metric_id', $billableMetricId)
            ->pluck('id')
            ->all();

        if ($chargeIds === []) {
            return $result;
        }

        /** @var BaseResult $usageResult */
        $usageResult = CustomerUsageService::call(
            customer: $subscription->customer,
            subscription: $subscription,
            applyTaxes: false,
            withCache: true,
        );

        $usage = $usageResult->raiseIfError()->usage;

        // Checked after the usage is built, not before: building it is the slow
        // part, so that is the window a metric change or a deletion can land in.
        // Before it the alert has only just been loaded by the job.
        if (! Alert::query()->where('id', $this->alert->id)->where('billable_metric_id', $billableMetricId)->exists()) {
            return $result;
        }

        ProcessAlertService::call(
            alert: $this->alert,
            alertable: $subscription,
            currentMetrics: $usage,
            expectedBillableMetricId: $billableMetricId,
        );

        return $result;
    }
}
