<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use Carbon\CarbonImmutable;
use App\Services\BaseResult;
use App\Models\InvoiceSubscription;
use App\Models\BillingPeriodBoundaries;
use App\Services\Subscriptions\DatesService;

/**
 * Port of Rails' Invoices::CreateInvoiceSubscriptionService
 * (app/services/invoices/create_invoice_subscription_service.rb) — creates
 * the invoice_subscriptions boundary rows, guarded against double billing
 * by InvoiceSubscription.matching?.
 */
class CreateInvoiceSubscriptionService extends \App\Services\BaseService
{
    private ?array $cachedBoundaries = null;

    public function __construct(
        private readonly Invoice $invoice,
        private readonly iterable $subscriptions,
        private readonly int $timestamp,
        private readonly string $invoicingReason,
        private readonly bool $refresh = false,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice_subscriptions');

        if ($this->duplicatedInvoices()) {
            return $result->serviceFailure(
                'duplicated_invoices',
                'Invoice subscription already exists with the boundaries',
            );
        }

        $result->invoice_subscriptions = [];

        foreach ($this->impactedSubscriptions() as $subscription) {
            $subscriptionBoundaries = $this->subscriptionsBoundaries()[$subscription->id];
            $boundaries = $this->terminationBoundaries($subscription, $subscriptionBoundaries);

            $row = InvoiceSubscription::query()->create([
                'organization_id' => $subscription->organization_id,
                'invoice_id' => $this->invoice->id,
                'subscription_id' => $subscription->id,
                'timestamp' => $boundaries->timestamp,
                'from_datetime' => $boundaries->fromDatetime,
                'to_datetime' => $boundaries->toDatetime,
                'charges_from_datetime' => $boundaries->chargesFromDatetime,
                'charges_to_datetime' => $boundaries->chargesToDatetime,
                'fixed_charges_from_datetime' => $boundaries->fixedChargesFromDatetime,
                'fixed_charges_to_datetime' => $boundaries->fixedChargesToDatetime,
                'recurring' => $this->invoicingReason === 'subscription_periodic',
                'invoicing_reason' => $this->invoicingReasonForSubscription($subscription),
            ]);

            $result->invoice_subscriptions = array_merge($result->invoice_subscriptions, [$row]);
        }

        return $result;
    }

    private function datetime(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->timestamp);
    }

    private function impactedSubscriptions(): array
    {
        if ($this->refresh) {
            return is_array($this->subscriptions) ? $this->subscriptions : iterator_to_array($this->subscriptions);
        }

        /** @var list<\App\Models\Subscription> $subscriptions */
        $subscriptions = is_array($this->subscriptions)
            ? array_values($this->subscriptions)
            : iterator_to_array($this->subscriptions);

        if ($this->invoicingReason === 'subscription_periodic') {
            $subscriptions = array_values(array_filter(
                $subscriptions,
                fn (\App\Models\Subscription $subscription) => $subscription->active(),
            ));
        }

        // uniq(&:id)
        $seen = [];

        return array_values(array_filter(
            $subscriptions,
            function (\App\Models\Subscription $subscription) use (&$seen): bool {
                if (isset($seen[$subscription->id])) {
                    return false;
                }
                $seen[$subscription->id] = true;

                return true;
            },
        ));
    }

    private function duplicatedInvoices(): bool
    {
        if ($this->invoicingReason !== 'subscription_periodic') {
            return false;
        }

        foreach ($this->subscriptionsBoundaries() as $subscriptionId => $boundaries) {
            $subscription = \App\Models\Subscription::query()->with('plan')->find($subscriptionId);

            if ($subscription !== null && InvoiceSubscription::matching($subscription, $boundaries)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, BillingPeriodBoundaries> keyed by subscription id */
    private function subscriptionsBoundaries(): array
    {
        if ($this->cachedBoundaries !== null) {
            return $this->cachedBoundaries;
        }

        $boundaries = [];

        foreach ($this->impactedSubscriptions() as $subscription) {
            $boundaries[$subscription->id] = $this->calculateBoundaries($subscription);
        }

        return $this->cachedBoundaries = $boundaries;
    }

    private function calculateBoundaries(\App\Models\Subscription $subscription): BillingPeriodBoundaries
    {
        $ds = $this->dateService($subscription);

        return new BillingPeriodBoundaries(
            fromDatetime: $ds->fromDatetime(),
            toDatetime: $ds->toDatetime(),
            chargesFromDatetime: $ds->chargesFromDatetime(),
            chargesToDatetime: $ds->chargesToDatetime(),
            chargesDuration: $ds->chargesDurationInDays(),
            timestamp: $this->datetime(),
            fixedChargesFromDatetime: $ds->fixedChargesFromDatetime(),
            fixedChargesToDatetime: $ds->fixedChargesToDatetime(),
            fixedChargesDuration: $ds->fixedChargesDurationInDays(),
        );
    }

    private function dateService(\App\Models\Subscription $subscription): DatesService
    {
        $currentUsage = $this->invoicingReason === 'progressive_billing';
        $currentUsage = $currentUsage
            || ($subscription->terminatedAt(CarbonImmutable::createFromTimestampUTC($this->timestamp))
                && $subscription->upgraded());

        // TODO(integration): verify signature against ported DatesService
        return DatesService::newInstance($subscription, $this->datetime(), $currentUsage);
    }

    /**
     * This method calculates boundaries for a terminated subscription. If
     * termination happens on the billing date, new boundaries are calculated
     * only if there is no invoice subscription object for the previous
     * period — the regular subscription amount for the previous period is
     * billed. On any other day, only the used dates in the current period
     * are billed.
     */
    private function terminationBoundaries(\App\Models\Subscription $subscription, BillingPeriodBoundaries $boundaries): BillingPeriodBoundaries
    {
        if (! $subscription->terminated() || $subscription->nextSubscription() !== null) {
            return $boundaries;
        }

        $datetime = $this->datetime();

        // Ensure the termination date is not the started_at date — in that
        // case boundaries are correct and we bill only one day.
        if ($datetime->subDay()->lt($subscription->started_at)) {
            return $boundaries;
        }

        // The date service has various checks for terminated subscriptions;
        // avoid them and fetch boundaries for current usage as if the
        // subscription was still active one day ago.
        $duplicate = clone $subscription;
        $duplicate->status = \App\Enums\SubscriptionStatus::Active->value;

        $datesService = DatesService::newInstance($duplicate, $datetime->subDay(), true);

        if ($datetime->lt($datesService->chargesToDatetime())) {
            return $boundaries;
        }

        if ($datetime->diffInSeconds($datesService->chargesToDatetime()) >= 86400) {
            return $boundaries;
        }

        // Calculate boundaries as if the subscription was not terminated.
        $ds = DatesService::newInstance($duplicate, $datetime, false);

        $previousPeriodBoundaries = new BillingPeriodBoundaries(
            fromDatetime: $ds->fromDatetime(),
            toDatetime: $ds->toDatetime(),
            chargesFromDatetime: $ds->chargesFromDatetime(),
            chargesToDatetime: $ds->chargesToDatetime(),
            chargesDuration: $ds->chargesDurationInDays(),
            timestamp: $datetime,
            fixedChargesFromDatetime: $ds->fixedChargesFromDatetime(),
            fixedChargesToDatetime: $ds->fixedChargesToDatetime(),
            fixedChargesDuration: $ds->fixedChargesDurationInDays(),
        );

        return InvoiceSubscription::matching($subscription, $previousPeriodBoundaries)
            ? $boundaries
            : $previousPeriodBoundaries;
    }

    /**
     * NOTE: upgrading is used as a not-persisted reason — it means one
     * subscription starting and a second one terminating.
     */
    private function invoicingReasonForSubscription(\App\Models\Subscription $subscription): string
    {
        if ($this->invoicingReason !== 'upgrading') {
            return $this->invoicingReason;
        }

        if ($subscription->terminatedAt(CarbonImmutable::createFromTimestampUTC($this->timestamp))) {
            return 'subscription_terminating';
        }

        return 'subscription_starting';
    }
}
