<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Models\Event;
use App\Enums\FeeType;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\CachedAggregation;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Models\Billing\Context as BillingContext;
use App\Services\BillableMetrics\AggregationFactory;
use App\Services\ChargeModels\Factory as ChargeModelFactory;

/**
 * Port of Rails' Fees::CreatePayInAdvanceService
 * (app/services/fees/create_pay_in_advance_service.rb) — bills a single
 * pay-in-advance event: aggregates the period total up to the event's
 * timestamp, bills the increment over what was already billed for the
 * charge in the period, persists the standalone fee and caches the
 * aggregation so the current-usage paths (wallet refresh) can net it out.
 *
 * TODO(port) — Rails resolves this through
 * Charges::PayInAdvanceAggregationService +
 * Charges::ApplyPayInAdvanceChargeModelService per pricing bucket; the port
 * nets the already-billed amount through the cached_aggregations rows
 * (current_aggregation = the total at the previous event) and prices with
 * the plain charge-model factory. Segmented charges, presentation
 * breakdowns and pricing units are not ported.
 */
class CreatePayInAdvanceService extends BaseService
{
    public function __construct(
        private readonly MeteredItem $meteredItem,
        private readonly BillingContext $billingContext,
        private readonly Event $event,
        private readonly mixed $billingAt = null,
        private readonly bool $estimate = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fees', 'invoice_id');

        $totalAggregation = $this->aggregateTotal();

        // The increment over the previously billed pay-in-advance events of
        // the same charge / period.
        $previous = $this->previousCachedAggregation();
        $aggregation = MoneyMath::compare((string) $totalAggregation, (string) ($previous?->current_aggregation ?? '0')) > 0
            ? MoneyMath::sub((string) $totalAggregation, (string) ($previous?->current_aggregation ?? '0'))
            : '0';

        $aggregationResult = new \App\Services\ChargeModels\AggregationResult(
            aggregation: $aggregation,
            currentUsageUnits: (string) $totalAggregation,
            count: null,
        );

        $chargeModelResult = ChargeModelFactory::newInstance(
            pricingStructure: $this->meteredItem->pricingStructure(),
            aggregationResult: $aggregationResult,
            periodRatio: $this->meteredItem->periodRatio(),
        )->apply();

        $fee = $this->initFee($chargeModelResult);

        if (! $this->estimate) {
            // Non-invoiceable fees are regrouped later by the
            // advance-charges aggregation — they must carry their taxes now,
            // no ComputeTaxesAndTotalsService step runs for them.
            if (! $this->meteredItem->invoiceable()) {
                ApplyTaxesService::call(
                    fee: $fee,
                    customer: $this->billingContext->customer(),
                )->raiseIfError();
            }

            $fee->save();

            // Rails: fee.created webhooks per fee (the fee.* webhook services
            // are a later slice — TODO(port)).

            $this->cacheAggregationResult($aggregationResult, $chargeModelResult);
        }

        $result->fees = [$fee];

        return $result;
    }

    private function initFee(
        \App\Services\ChargeModels\ChargeModelResult $chargeModelResult,
    ): Fee {
        $roundedAmount = MoneyMath::roundTo((string) $chargeModelResult->amount, $this->meteredItem->currencyExponent());

        return new Fee([
            'organization_id' => $this->billingContext->organizationId(),
            'billing_entity_id' => $this->billingContext->customer()->billing_entity_id,
            'subscription_id' => $this->billingContext->subscription()->id,
            'charge_id' => $this->meteredItem->chargeId(),
            'amount_cents' => MoneyMath::round(MoneyMath::mul($roundedAmount, (string) $this->meteredItem->subunitToUnit())),
            'precise_amount_cents' => MoneyMath::mul((string) $chargeModelResult->amount, (string) $this->meteredItem->subunitToUnit()),
            'amount_currency' => $this->meteredItem->currency(),
            'fee_type' => FeeType::Charge,
            'invoiceable_type' => 'Charge',
            'invoiceable_id' => $this->meteredItem->chargeId(),
            'units' => (string) $chargeModelResult->units,
            'total_aggregated_units' => (string) ($chargeModelResult->totalAggregatedUnits ?? $chargeModelResult->units),
            'properties' => $this->meteredItem->filteredForChargeBoundaries(),
            'events_count' => $chargeModelResult->count,
            'pay_in_advance_event_id' => $this->event->id,
            'pay_in_advance_event_transaction_id' => $this->event->transaction_id,
            'payment_status' => \App\Enums\FeePaymentStatus::Pending,
            'pay_in_advance' => true,
            'taxes_amount_cents' => 0,
            'taxes_precise_amount_cents' => '0',
            'unit_amount_cents' => MoneyMath::round(MoneyMath::mul((string) $chargeModelResult->unitAmount, (string) $this->meteredItem->subunitToUnit())),
            'precise_unit_amount' => (string) $chargeModelResult->unitAmount,
            'grouped_by' => [],
            'amount_details' => $chargeModelResult->amountDetails ?? [],
        ]);
    }

    /**
     * Rails: the aggregation boundaries stop at the event's timestamp, so
     * the total covers the events up to this one only. The current-usage
     * adjustment is NOT applied here (is_current_usage stays off) — the
     * increment is resolved against the cached rows below.
     */
    private function aggregateTotal(): string
    {
        $boundaries = [
            'from_datetime' => $this->meteredItem->boundaries->chargesFromDatetime,
            'to_datetime' => \App\Support\Utils\Datetime::parseIso8601($this->event->timestamp) ?? $this->meteredItem->boundaries->chargesToDatetimeValue(),
            'charges_duration' => $this->meteredItem->boundaries->chargesDuration,
            'max_timestamp' => $this->event->timestamp,
        ];

        // currentUsage: true only picks the payable-in-advance aggregator
        // class; the adjustment branch reads is_current_usage from the
        // aggregation options, deliberately left off.
        $aggregationService = AggregationFactory::newInstance(
            meteredItem: $this->meteredItem,
            billingContext: $this->billingContext,
            currentUsage: true,
            boundaries: $boundaries,
            filters: ['charge_id' => $this->meteredItem->chargeId()],
            aggregationOptions: [
                'free_units_per_events' => (int) ($this->meteredItem->properties()['free_units_per_events'] ?? 0),
                'free_units_per_total_aggregation' => (string) ($this->meteredItem->properties()['free_units_per_total_aggregation'] ?? '0'),
                'is_pay_in_advance' => true,
            ],
        );

        return (string) $aggregationService->aggregate()->aggregation;
    }

    /**
     * The last cached row of this charge / period — the previous events'
     * already-billed state.
     */
    private function previousCachedAggregation(): ?CachedAggregation
    {
        // NOTE: second-precision column comparison — the model bindings
        // truncate microseconds, so a raw `<=` silently excludes a cached row
        // written in the same second as the event's timestamp.
        $from = \Illuminate\Support\Facades\Date::parse($this->meteredItem->boundaries->chargesFromDatetime);
        $from->microsecond = 0;

        return CachedAggregation::query()
            ->where('organization_id', $this->event->organization_id)
            ->where('external_subscription_id', $this->event->external_subscription_id)
            ->where('charge_id', $this->meteredItem->chargeId())
            ->where('timestamp', '>=', $from)
            ->whereRaw('date_trunc(\'second\', timestamp) <= ?::timestamp', [
                \App\Support\Utils\Datetime::parseIso8601($this->event->timestamp) ?? now(),
            ])
            ->where('grouped_by', '[]')
            ->where('event_transaction_id', '!=', $this->event->transaction_id)
            ->orderByDesc('timestamp')->latest()
            ->first();
    }

    /**
     * Rails: `cache_aggregation_result` — the row the current-usage paths
     * read back (handle_in_advance_current_usage): current_aggregation is
     * the total aggregated at event time, max_aggregation the incremental
     * amount actually billed now.
     */
    private function cacheAggregationResult(
        \App\Services\ChargeModels\AggregationResult $aggregationResult,
        \App\Services\ChargeModels\ChargeModelResult $chargeModelResult,
    ): void {
        $currentAggregation = $aggregationResult->currentUsageUnits ?? $aggregationResult->aggregation;
        $billedAggregation = $aggregationResult->aggregation;

        if (MoneyMath::compare((string) $currentAggregation, '0') === 0
            && MoneyMath::compare((string) $billedAggregation, '0') === 0
            && $chargeModelResult->amountDetails === null) {
            return;
        }

        CachedAggregation::query()->create([
            'organization_id' => $this->event->organization_id,
            'event_transaction_id' => $this->event->transaction_id,
            'timestamp' => $this->billingAt ?? $this->event->timestamp,
            'external_subscription_id' => $this->event->external_subscription_id,
            'charge_id' => $this->meteredItem->chargeId(),
            'current_aggregation' => (string) $currentAggregation,
            'current_amount' => $aggregationResult->customAggregation['amount'] ?? null,
            'max_aggregation' => (string) $billedAggregation,
            'grouped_by' => [],
        ]);
    }
}
