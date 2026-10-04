<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Fee;
use App\Models\Event;
use App\Models\Charge;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\SubscriptionStatus;
use App\Jobs\Fees\CreatePayInAdvanceJob;
use App\Services\Subscriptions\DatesService;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Jobs\Invoices\CreatePayInAdvanceChargeJob;

/**
 * Port of Rails' Events::PayInAdvanceService
 * (app/services/events/pay_in_advance_service.rb) — for every
 * pay-in-advance charge matching the event's billable metric, schedules the
 * fee (non-invoiceable) or invoice (invoiceable) job.
 *
 * TODO(port): the Kafka producer branch (ClickHouse dual-write) — the port
 * always runs the Postgres path; Events::PayInAdvanceMeteredItemsResolver's
 * billing segments (segmented charges) — one metered item per charge.
 */
class PayInAdvanceService extends BaseService
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(private readonly Event $event)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('event');

        if ($this->billableMetric() === null) {
            return $result;
        }

        if (! $this->canCreateFee()) {
            return $result;
        }

        if ($this->alreadyProcessed()) {
            return $result;
        }

        foreach ($this->payInAdvanceCharges() as $charge) {
            $meteredItem = $this->meteredItemFor($charge);

            if ($meteredItem === null) {
                continue;
            }

            $this->enqueue($meteredItem);
        }

        $result->event = $this->event;

        return $result;
    }

    private function billableMetric(): ?\App\Models\BillableMetric
    {
        return $this->memo['billable_metric'] ??= $this->event->organization
            ->billableMetrics()
            ->where('code', $this->event->code)
            ->first();
    }

    /**
     * Rails: `already_processed?` — a fee already exists for the event's
     * transaction id.
     */
    private function alreadyProcessed(): bool
    {
        return Fee::query()
            ->where('organization_id', $this->event->organization_id)
            ->where('pay_in_advance_event_transaction_id', $this->event->transaction_id)
            ->exists();
    }

    /**
     * Rails: `can_create_fee?` — count_agg and custom_agg are the only
     * aggregations that don't require the metric's field on the event.
     */
    private function canCreateFee(): bool
    {
        $metric = $this->billableMetric();

        if ($metric === null) {
            return false;
        }

        if ($metric->countAgg() || $metric->customAgg()) {
            return true;
        }

        return ($this->event->properties[$metric->field_name] ?? null) !== null
            && ($this->event->properties[$metric->field_name] ?? null) !== '';
    }

    /** @return list<Charge> */
    private function payInAdvanceCharges(): array
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return [];
        }

        return $subscription->plan
            ->charges()
            ->join('billable_metrics', 'billable_metrics.id', '=', 'charges.billable_metric_id')
            ->where('billable_metrics.code', $this->event->code)
            ->where('charges.pay_in_advance', true)
            ->select('charges.*')
            ->get()
            ->all();
    }

    private function subscription(): ?\App\Models\Subscription
    {
        return $this->memo['subscription'] ??= $this->event->organization
            ->subscriptions()
            ->where('external_id', $this->event->external_subscription_id)
            ->where('status', SubscriptionStatus::Active->value)
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Rails resolves the aggregation boundaries through the metered-items
     * resolver; the port derives them from the subscription's current-usage
     * dates at the event's timestamp (segmented charges are TODO(port)).
     */
    private function meteredItemFor(Charge $charge): ?MeteredItem
    {
        $dateService = DatesService::newInstance(
            $this->subscription(),
            \App\Support\Utils\Datetime::parseIso8601($this->event->timestamp) ?? now(),
            currentUsage: true,
        );

        if ($dateService->chargesFromDatetime() === null || $dateService->chargesToDatetime() === null) {
            return null;
        }

        return MeteredItem::fromCharge($charge, new \App\Models\BillingPeriodBoundaries(
            fromDatetime: $dateService->fromDatetime(),
            toDatetime: $dateService->toDatetime(),
            chargesFromDatetime: $dateService->chargesFromDatetime(),
            chargesToDatetime: $dateService->chargesToDatetime(),
            chargesDuration: $dateService->chargesDurationInDays(),
            timestamp: $this->event->timestamp,
        ));
    }

    private function enqueue(MeteredItem $meteredItem): void
    {
        if ($meteredItem->invoiceable()) {
            CreatePayInAdvanceChargeJob::dispatch(
                timestamp: \App\Support\Utils\Datetime::serialize($this->event->timestamp),
                chargeId: $meteredItem->chargeId(),
                eventId: $this->event->id,
            );
        } else {
            CreatePayInAdvanceJob::dispatch(
                chargeId: $meteredItem->chargeId(),
                eventId: $this->event->id,
            );
        }
    }
}
