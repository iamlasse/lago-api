<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages\UsageThresholds;

use App\Services\BaseResult;
use App\Models\LifetimeUsage;
use App\Models\UsageThreshold;

/**
 * Port of Rails' LifetimeUsages::UsageThresholds::CheckService
 * (app/services/lifetime_usages/usage_thresholds/check_service.rb) — which
 * usage thresholds the lifetime usage has now passed, taking the already
 * progressively billed amount into account.
 */
class CheckService extends \App\Services\BaseService
{
    public function __construct(
        private readonly LifetimeUsage $lifetimeUsage,
        private readonly int $progressiveBilledAmount = 0,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('passed_thresholds');

        /** @var \Illuminate\Database\Eloquent\Collection<int, UsageThreshold> $thresholds */
        $thresholds = $this->lifetimeUsage->subscription->applicableUsageThresholds();

        $result->passed_thresholds = [];

        if ($thresholds->isEmpty()) {
            return $result;
        }

        /** @var \Illuminate\Support\Collection<int, UsageThreshold> $fixedThresholds */
        $fixedThresholds = $thresholds
            ->filter(fn (UsageThreshold $t): bool => ! $t->recurring)
            ->sortBy('amount_cents')
            ->values();

        // There is only 1 recurring threshold, `first` will return it or null.
        $recurringThreshold = $thresholds->first(fn (UsageThreshold $t): bool => (bool) $t->recurring);

        // Calculate the actual current usage, we need to subtract the already
        // progressively billed amount as we might be passing the recurring
        // threshold multiple times per period.
        $actualCurrentUsage = (int) $this->lifetimeUsage->current_usage_amount_cents - $this->progressiveBilledAmount;

        // We can end up in a situation where this goes below zero, in that case
        // no thresholds are passed.
        if ($actualCurrentUsage < 0) {
            return $result;
        }

        $invoicedUsage = (int) $this->lifetimeUsage->historical_usage_amount_cents
            + (int) $this->lifetimeUsage->invoiced_usage_amount_cents
            + $this->progressiveBilledAmount;

        // In case there are no fixed thresholds, max() is null which (int) makes 0.
        $largestThresholdAmount = (int) $fixedThresholds->max('amount_cents');
        $totalUsage = $invoicedUsage + $actualCurrentUsage;

        // First check the fixed thresholds.
        if ($invoicedUsage < $largestThresholdAmount) {
            // We're below some thresholds: filter out those that we've already
            // invoiced, and keep those that we've passed based on total_usage.
            $result->passed_thresholds = $fixedThresholds
                ->filter(fn (UsageThreshold $threshold): bool => (int) $threshold->amount_cents > $invoicedUsage
                    && (int) $threshold->amount_cents <= $totalUsage)
                ->values()
                ->all();

            if ($recurringThreshold !== null
                && $totalUsage - $largestThresholdAmount >= (int) $recurringThreshold->amount_cents) {
                $rows = $result->passed_thresholds ?? [];
                $rows[] = $recurringThreshold;
                $result->passed_thresholds = $rows;
            }
        } elseif ($recurringThreshold !== null) {
            $recurringRemainder = $invoicedUsage % (int) $recurringThreshold->amount_cents;

            if ($actualCurrentUsage + $recurringRemainder >= (int) $recurringThreshold->amount_cents) {
                $rows = $result->passed_thresholds ?? [];
                $rows[] = $recurringThreshold;
                $result->passed_thresholds = $rows;
            }
        }

        return $result;
    }
}
