<?php

declare(strict_types=1);

namespace App\Services\LifetimeUsages;

use App\Services\BaseResult;
use App\Models\LifetimeUsage;
use App\Models\UsageThreshold;

/**
 * Port of Rails' LifetimeUsages::UsageThresholdsCompletionService
 * (app/services/lifetime_usages/usage_thresholds_completion_service.rb) —
 * the ordered threshold walk backing the lifetime-usage serializers: passed
 * thresholds with their reached_at, then the upcoming ones with completion
 * ratios.
 */
class UsageThresholdsCompletionService extends \App\Services\BaseService
{
    private ?\App\Models\Subscription $subscription = null;

    private ?\App\Models\Organization $organization = null;

    public function __construct(private readonly LifetimeUsage $lifetimeUsage)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('usage_thresholds');

        $this->subscription = $this->lifetimeUsage->subscription;
        $this->organization = $this->subscription->organization;

        /** @var \Illuminate\Database\Eloquent\Collection<int, UsageThreshold> $usageThresholds */
        $usageThresholds = $this->lifetimeUsage->subscription->applicableUsageThresholds();

        $result->usage_thresholds = [];

        if ($usageThresholds->isEmpty()) {
            return $result;
        }

        $lifetimeUsage = $this->lifetimeUsage;
        $totalAmountCents = $lifetimeUsage->totalAmountCents();

        $largestNonRecurringThresholdAmountCents = (int) ($usageThresholds
            ->filter(fn (UsageThreshold $t): bool => ! $t->recurring)
            ->max('amount_cents') ?? 0);

        $recurringThreshold = $usageThresholds->first(fn (UsageThreshold $t): bool => (bool) $t->recurring);

        // Split non-recurring thresholds into 2 groups: passed and not passed.
        [$passedThresholds, $notPassedThresholds] = $usageThresholds
            ->filter(fn (UsageThreshold $t): bool => ! $t->recurring)
            ->sortBy('amount_cents')
            ->values()
            ->partition(fn (UsageThreshold $threshold): bool => (int) $threshold->amount_cents <= $totalAmountCents);

        $passedThresholds = $passedThresholds->values();
        $notPassedThresholds = $notPassedThresholds->values();

        $subscriptionIds = $this->organization->subscriptions()
            ->where('external_id', $this->subscription->external_id)
            ->where('subscription_at', $this->subscription->subscription_at)
            ->whereNull('canceled_at')
            ->pluck('id')
            ->all();

        // Add all passed thresholds to the result, completion rate is 100%.
        foreach ($passedThresholds as $threshold) {
            // Fallback to now if the invoice is not yet generated.
            $reachedAt = \App\Models\AppliedUsageThreshold::query()
                ->where('usage_threshold_id', $threshold->id)
                ->join('invoices', 'invoices.id', '=', 'applied_usage_thresholds.invoice_id')
                ->join('invoice_subscriptions', 'invoice_subscriptions.invoice_id', '=', 'invoices.id')
                ->whereIn('invoice_subscriptions.subscription_id', $subscriptionIds)
                ->max('applied_usage_thresholds.created_at')
                ?? now();

            $this->addUsageThreshold($result, $threshold, (int) $threshold->amount_cents, 1.0, $reachedAt);
        }

        $lastPassedThresholdAmount = (int) ($passedThresholds->last()?->amount_cents ?? 0);

        // If we have a not-passed threshold that means we can ignore the recurring
        // one; if not_passed_thresholds is empty, we need to check the recurring one.
        if ($notPassedThresholds->isEmpty()) {
            if ($recurringThreshold !== null) {
                $this->addRecurringThreshold($result, $recurringThreshold, $lastPassedThresholdAmount, $subscriptionIds);
            }
        } else {
            $threshold = $notPassedThresholds->shift();

            $ratio = ((float) $totalAmountCents - (float) $lastPassedThresholdAmount)
                / ((float) $threshold->amount_cents - (float) $lastPassedThresholdAmount);

            $this->addUsageThreshold($result, $threshold, (int) $threshold->amount_cents, $ratio, null);

            foreach ($notPassedThresholds as $notPassed) {
                $this->addUsageThreshold($result, $notPassed, (int) $notPassed->amount_cents, 0.0, null);
            }

            // Add recurring at the end if it's there.
            if ($recurringThreshold !== null) {
                $this->addUsageThreshold(
                    $result,
                    $recurringThreshold,
                    $largestNonRecurringThresholdAmountCents + (int) $recurringThreshold->amount_cents,
                    0.0,
                    null,
                );
            }
        }

        return $result;
    }

    private function addUsageThreshold(BaseResult $result, UsageThreshold $usageThreshold, int $amountCents, float $completionRatio, $reachedAt): void
    {
        $rows = $result->usage_thresholds ?? [];

        $rows[] = [
            'usage_threshold' => $usageThreshold,
            'amount_cents' => $amountCents,
            'completion_ratio' => $completionRatio,
            'reached_at' => $reachedAt,
        ];

        $result->usage_thresholds = $rows;
    }

    private function addRecurringThreshold(BaseResult $result, UsageThreshold $recurringThreshold, int $lastPassedThresholdAmount, array $subscriptionIds): void
    {
        $recurringAmount = (int) $recurringThreshold->amount_cents;
        $totalAmountCents = $this->lifetimeUsage->totalAmountCents();

        $recurringRemainder = ($lastPassedThresholdAmount + $totalAmountCents) % $recurringAmount;

        $appliedThresholds = \App\Models\AppliedUsageThreshold::query()
            ->where('usage_threshold_id', $recurringThreshold->id)
            ->join('invoices', 'invoices.id', '=', 'applied_usage_thresholds.invoice_id')
            ->join('invoice_subscriptions', 'invoice_subscriptions.invoice_id', '=', 'invoices.id')
            ->whereIn('invoice_subscriptions.subscription_id', $subscriptionIds)
            ->orderBy('applied_usage_thresholds.lifetime_usage_amount_cents')
            ->get([
                'applied_usage_thresholds.lifetime_usage_amount_cents',
                'applied_usage_thresholds.created_at',
            ]);

        $occurence = intdiv($totalAmountCents - $lastPassedThresholdAmount, $recurringAmount);

        for ($i = 0; $i < $occurence; $i++) {
            $amountCents = $lastPassedThresholdAmount + (($i + 1) * $recurringAmount);

            $applied = $appliedThresholds->first(
                fn ($applied): bool => (int) $applied->lifetime_usage_amount_cents >= $amountCents,
            );

            $this->addUsageThreshold($result, $recurringThreshold, $amountCents, 1.0, $applied?->created_at ?? now());
        }

        $this->addUsageThreshold(
            $result,
            $recurringThreshold,
            $totalAmountCents - $recurringRemainder + $recurringAmount,
            ((float) $recurringRemainder) / ((float) $recurringAmount),
            null,
        );
    }
}
