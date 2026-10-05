<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Subscription;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;
use App\Services\UsageThresholds\UpdateService;

/**
 * Port of Rails' Subscriptions::UpdateUsageThresholdsService
 * (app/services/subscriptions/update_usage_thresholds_service.rb) — attaches
 * usage thresholds to a subscription; once attached, the plan-override
 * child's thresholds are discarded and the lifetime usage ledger is flagged
 * for an invoiced-usage recalculation.
 */
class UpdateUsageThresholdsService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly array $usageThresholdsParams,
        private readonly bool $partial,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of();
        $subscription = $this->subscription;

        if (! $subscription->organization->progressiveBillingEnabled()) {
            return $result;
        }

        DB::transaction(function () use ($subscription): void {
            UpdateService::callBang(
                model: $subscription,
                usageThresholdsParams: $this->usageThresholdsParams,
                partial: $this->partial,
            );

            // NOTE: Once we attach UT to the subscription, we should delete all
            // UT attached to the plan override.
            if ($subscription->plan->parent_id !== null) {
                $subscription->plan->usageThresholds()->update(['deleted_at' => now()]);
            }
        });

        $thresholdCount = $subscription->usageThresholds()->count();

        if ($thresholdCount > 0) {
            $subscription->lifetimeUsage?->update(['recalculate_invoiced_usage' => true]);
        }

        return $result;
    }
}
