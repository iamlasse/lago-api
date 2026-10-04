<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Charge;
use App\Support\Utils\Datetime;
use App\Models\BillingPeriodBoundaries;
use App\Services\Subscriptions\DatesService;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Models\Billing\Context as BillingContext;

/**
 * Port of Rails' PayInAdvanceArguments (app/models/pay_in_advance_arguments.rb)
 * — centralizes the rebuild of the pay-in-advance job arguments: the jobs
 * carry charge_id + event_id and the metered item / billing context are
 * resolved here at perform time.
 */
class PayInAdvanceArguments
{
    public function __construct(
        private readonly string $chargeId,
        private readonly string $eventId,
    ) {}

    public function event(): Event
    {
        /** @var Event */
        return Event::query()->findOrFail($this->eventId);
    }

    public function charge(): Charge
    {
        /** @var Charge */
        return Charge::query()->findOrFail($this->chargeId);
    }

    public function meteredItem(): MeteredItem
    {
        $event = $this->event();

        $dateService = DatesService::newInstance(
            $this->subscription(),
            Datetime::parseIso8601($event->timestamp) ?? now(),
            currentUsage: true,
        );

        return MeteredItem::fromCharge($this->charge(), new BillingPeriodBoundaries(
            fromDatetime: $dateService->fromDatetime(),
            toDatetime: $dateService->toDatetime(),
            chargesFromDatetime: $dateService->chargesFromDatetime(),
            chargesToDatetime: $dateService->chargesToDatetime(),
            chargesDuration: $dateService->chargesDurationInDays(),
            timestamp: $event->timestamp,
        ));
    }

    public function billingContext(): ?BillingContext
    {
        $subscription = $this->subscription();

        return $subscription === null ? null : BillingContext::fromSubscription($subscription);
    }

    private function subscription(): ?\App\Models\Subscription
    {
        $event = $this->event();

        return $event->organization
            ->subscriptions()
            ->where('external_id', $event->external_subscription_id)
            ->where('status', \App\Enums\SubscriptionStatus::Active->value)
            ->orderByDesc('started_at')
            ->first();
    }
}
