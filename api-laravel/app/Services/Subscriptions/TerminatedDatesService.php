<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\InvoiceSubscription;
use App\Models\Subscription;
use App\Services\Subscriptions\DatesService;
use Carbon\CarbonImmutable;

/**
 * Port of Rails' Subscriptions::TerminatedDatesService
 * (app/services/subscriptions/terminated_dates_service.rb).
 *
 * Recomputes a terminated subscription's billing boundaries "as if the
 * subscription had not been terminated", when the termination happened
 * between the last billing day and the current one.
 */
class TerminatedDatesService
{
    protected Subscription $subscription;

    protected ?CarbonImmutable $timestamp;

    protected DatesService $dateService;

    protected bool $matchInvoiceSubscription;

    public function __construct(
        Subscription $subscription,
        object $invoice,
        DatesService $dateService,
        bool $matchInvoiceSubscription = true,
    ) {
        $this->subscription = $subscription;

        $timestamp = $invoice->invoiceSubscriptions->first()?->timestamp;
        $this->timestamp = $timestamp !== null ? CarbonImmutable::instance($timestamp)->utc() : null;

        $this->dateService = $dateService;
        $this->matchInvoiceSubscription = $matchInvoiceSubscription;
    }

    public function call(): DatesService
    {
        if (! $this->subscription->terminated() || $this->subscription->nextSubscription() !== null) {
            return $this->dateService;
        }

        $timestamp = $this->timestamp;

        if ($timestamp === null) {
            return $this->dateService;
        }

        // First we need to ensure that termination date is not started_at date.
        // In that case boundaries are correct and we should bill only one day.
        if ($timestamp->subDay()->lt(CarbonImmutable::instance($this->subscription->started_at)->utc())) {
            return $this->dateService;
        }

        // Date service has various checks for terminated subscriptions. We want
        // to avoid it and fetch boundaries for current usage (current period)
        // but when subscription was active (one day ago).
        $duplicate = $this->duplicateActive();

        $newDatesService = DatesService::newInstance($duplicate, $timestamp->subDay(), true);

        $newChargesToDatetime = $newDatesService->chargesToDatetime();
        if ($newChargesToDatetime !== null) {
            if ($timestamp->lt($newChargesToDatetime)) {
                return $this->dateService;
            }

            if ($timestamp->diffInSeconds($newChargesToDatetime) >= 86400) {
                return $this->dateService;
            }
        }

        $newFixedChargesToDatetime = $newDatesService->fixedChargesToDatetime();
        if ($newFixedChargesToDatetime !== null) {
            if ($timestamp->lt($newFixedChargesToDatetime)) {
                return $this->dateService;
            }

            if ($timestamp->diffInSeconds($newFixedChargesToDatetime) >= 86400) {
                return $this->dateService;
            }
        }

        // We should calculate boundaries as if subscription was not terminated
        $newDatesService = DatesService::newInstance($duplicate, $timestamp, false);

        if (! $this->matchInvoiceSubscription) {
            return $newDatesService;
        }

        return $this->matchingInvoiceSubscription($newDatesService) ? $this->dateService : $newDatesService;
    }

    /**
     * Rails: `subscription.dup.tap { |s| s.status = :active }` — an unpersisted
     * duplicate used so the terminated-subscription special cases in the date
     * service are bypassed.
     */
    protected function duplicateActive(): Subscription
    {
        $duplicate = $this->subscription->replicate();
        $duplicate->setRelation('plan', $this->subscription->plan);
        $duplicate->setRelation('customer', $this->subscription->customer);
        $duplicate->status = 1; // :active

        return $duplicate;
    }

    protected function matchingInvoiceSubscription(DatesService $dateService): bool
    {
        $boundaries = new \App\Models\BillingPeriodBoundaries(
            fromDatetime: $dateService->fromDatetime(),
            toDatetime: $dateService->toDatetime(),
            chargesFromDatetime: $dateService->chargesFromDatetime(),
            chargesToDatetime: $dateService->chargesToDatetime(),
            chargesDuration: $dateService->chargesDurationInDays(),
            timestamp: $this->timestamp,
            fixedChargesFromDatetime: $dateService->fixedChargesFromDatetime(),
            fixedChargesToDatetime: $dateService->fixedChargesToDatetime(),
            fixedChargesDuration: $dateService->fixedChargesDurationInDays(),
        );

        return InvoiceSubscription::matching($this->subscription, $boundaries, true);
    }
}
