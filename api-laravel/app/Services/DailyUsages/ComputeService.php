<?php

declare(strict_types=1);

namespace App\Services\DailyUsages;

use App\Models\Fee;
use App\Models\DailyUsage;
use Carbon\CarbonInterface;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Support\SubscriptionUsage;
use App\Services\Subscriptions\DatesService;
use App\Services\Invoices\CustomerUsageService;
use App\Serializers\V1\Customers\UsageSerializer;

/**
 * Port of Rails' DailyUsages::ComputeService
 * (app/services/daily_usages/compute_service.rb) — computes one
 * subscription's daily usage snapshot for the day before `timestamp` (in
 * the customer's timezone) and stores it, diffed against the previous
 * snapshot of the same billing period.
 */
class ComputeService extends BaseService
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly CarbonInterface $timestamp,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('daily_usage');

        if ($this->subscriptionBillingDay()) {
            // Usage on billing day will be computed using the periodic invoice
            // as we cannot rely on the caching mechanism.
            return $result;
        }

        if ($this->existingDailyUsage() !== null) {
            $result->daily_usage = $this->existingDailyUsage();

            return $result;
        }

        $currentUsage = $this->currentUsage();
        $fees = array_values(array_filter(
            $currentUsage->fees,
            fn (Fee $fee): bool => $this->nonZero($fee)
        ));

        if ($fees !== []) {
            // Rails mutates `current_usage.fees` in place; SubscriptionUsage
            // is immutable in the port, so a filtered copy feeds the
            // serializer.
            $filteredUsage = new SubscriptionUsage(
                fromDatetime: $currentUsage->fromDatetime,
                toDatetime: $currentUsage->toDatetime,
                issuingDate: $currentUsage->issuingDate,
                currency: $currentUsage->currency,
                amountCents: $currentUsage->amountCents,
                totalAmountCents: $currentUsage->totalAmountCents,
                taxesAmountCents: $currentUsage->taxesAmountCents,
                fees: $fees,
                projections: $currentUsage->projections,
            );

            $dailyUsage = new DailyUsage([
                'organization_id' => $this->subscription->organization_id,
                'customer_id' => $this->subscription->customer_id,
                'subscription_id' => $this->subscription->id,
                'external_subscription_id' => $this->subscription->external_id,
                'usage' => (new UsageSerializer($filteredUsage, ['includes' => ['charges_usage']]))->serialize(),
                'from_datetime' => $currentUsage->fromDatetime,
                'to_datetime' => $currentUsage->toDatetime,
                'refreshed_at' => $this->timestamp,
                'usage_date' => $this->usageDate(),
            ]);

            $dailyUsage->usage_diff = $this->diffUsage($dailyUsage);

            $dailyUsage->save();

            $result->daily_usage = $dailyUsage;
        }

        return $result;
    }

    /**
     * Rails: `current_usage` — the live (or cached) current-usage snapshot.
     * A subscription terminated after the enqueue time cannot rely on the
     * cache; the timestamp is forced so the boundaries are computed at the
     * (past) timestamp instead of now.
     */
    private function currentUsage(): SubscriptionUsage
    {
        $withCache = ! ($this->subscription->terminated()
            && $this->subscription->terminated_at !== null
            && $this->subscription->terminated_at->gt($this->timestamp));

        return CustomerUsageService::callBang(
            customer: $this->subscription->customer,
            subscription: $this->subscription,
            timestamp: $withCache ? null : $this->timestamp,
            applyTaxes: false,
            withCache: $withCache,
            maxTimestamp: $withCache ? null : $this->timestamp,
        )->usage;
    }

    private function existingDailyUsage(): ?DailyUsage
    {
        return DailyUsage::query()
            ->usageDateInTimezone($this->usageDate())
            ->where('daily_usages.subscription_id', $this->subscription->id)
            ->first();
    }

    /** Rails: `diff_usage` — diff against the previous snapshot of the period. */
    private function diffUsage(DailyUsage $dailyUsage): array
    {
        return ComputeDiffService::callBang(dailyUsage: $dailyUsage)->usage_diff;
    }

    /**
     * Rails: `subscription_billing_day?` — true when the day before
     * `timestamp` (in the customer timezone) is the previous period's
     * beginning: the periodic invoice covers it.
     */
    private function subscriptionBillingDay(): bool
    {
        $previousBillingDateInTimezone = DatesService::newInstance(
            $this->subscription,
            $this->timestamp,
            currentUsage: true,
        )->previousBeginningOfPeriod()
            ->setTimezone($this->customerTimezone())
            ->toDateString();

        return $this->dateInTimezone()->toDateString() === $previousBillingDateInTimezone;
    }

    private function dateInTimezone(): CarbonInterface
    {
        return $this->timestamp->copy()->setTimezone($this->customerTimezone())->startOfDay();
    }

    /** The computed day: the day before `timestamp`, in the customer timezone. */
    private function usageDate(): CarbonInterface
    {
        return $this->dateInTimezone()->subDay();
    }

    private function customerTimezone(): string
    {
        return $this->subscription->customer->applicableTimezone();
    }

    /** Rails: Fee#non_zero? — units, amount or events count above zero. */
    private function nonZero(Fee $fee): bool
    {
        return bccomp((string) $fee->units, '0', 10) === 1
            || (int) $fee->amount_cents > 0
            || (int) $fee->events_count > 0;
    }
}
