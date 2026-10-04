<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Invoice;
use App\Support\MoneyMath;
use App\Models\AdjustedFee;
use App\Models\Subscription;
use App\Services\BaseResult;
use NotImplementedException;
use App\Enums\FeePaymentStatus;
use App\Models\CachedAggregation;
use Illuminate\Support\Facades\DB;
use App\Services\Fees\ChargeService\Options;
use App\Services\Fees\ChargeService\Aggregator;
use App\Services\ChargeModels\AggregationResult;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Services\ChargeModels\Factory as ChargeModelFactory;

/**
 * Port of Rails' Fees::ChargeService (app/services/fees/charge_service.rb)
 * — computes the per-charge fee for every charge model.
 *
 * M1 seams (see the TODO(port) markers):
 * - charge filters: Rails creates one fee per ChargeFilter plus a fallback
 *   fee for unfiltered events; filters need the events store (M2). Only the
 *   unfiltered fee is created.
 * - Subscriptions::ChargeCacheMiddleware is not ported; aggregation runs
 *   live via Fees\ChargeService\Aggregator → the BillableMetrics
 *   aggregation services (M2).
 * - presentation breakdowns and pricing units are not ported.
 */
class ChargeService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly MeteredItem $meteredItem,
        private readonly Subscription $subscription,
        private readonly ?Options $options = null,
        private readonly ?string $plan = null,
        private readonly ?object $customer = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('fees', 'cached_aggregations');

        if (! $this->options()->currentUsage() && $this->alreadyBilled($result)) {
            return $result;
        }

        $this->initMeteredItemsFees($result);

        if ($this->options()->currentUsage()) {
            return $result;
        }

        if ($this->invoice === null || ! $this->invoice->isProgressiveBilling()) {
            $this->initTrueUpFee($result);
        }

        if (! $result->success()) {
            return $result;
        }

        DB::transaction(function () use ($result): void {
            $fees = $result->fees ?? [];
            $kept = [];

            foreach ($fees as $fee) {
                if (! $this->shouldPersistFee($fee, $fees)) {
                    continue;
                }

                $kept[] = $fee;

                if ($this->options()->invoicePreview()) {
                    continue;
                }

                // BUGFIX(port): Rails' fee.dup keeps the parent fee OBJECT, so
                // true_up_parent_fee_id resolves at save time even though the
                // parent is built in memory first — here the id snapshot was
                // still null. Resolve it from the carried relation now that
                // the parent fee (earlier in the list) has been saved.
                if ($fee->true_up_parent_fee_id === null && $fee->relationLoaded('trueUpParentFee')) {
                    $fee->true_up_parent_fee_id = $fee->trueUpParentFee?->id;
                }

                $fee->save();

                // TODO(port): AdjustedFee update! branch for draft invoices —
                // requires the full adjusted-fee matching (charge filter +
                // grouped_by keys) ported with the filters pipeline (M2).
            }

            $result->fees = $kept;
        });

        return $result;
    }

    private function options(): Options
    {
        return $this->options ?? Options::default();
    }

    /**
     * NOTE: Rails creates a fee per charge filter plus one for events not
     * matching any filter. TODO(port): the filters branch (M2) — for now a
     * single fee without filter is created.
     */
    private function initMeteredItemsFees(BaseResult $result): void
    {
        $result->fees = [];

        $fees = $this->computeFees($this->meteredItem, $result);

        if ($fees === null) {
            return;
        }

        if ($fees === [] && $this->skipCachingOfNonPersistableFee()) {
            $fees = $this->hydrateNonPersistableFees($this->meteredItem);
        }

        foreach ($fees as $fee) {
            if ($fee !== null) {
                $result->fees = array_merge($result->fees ?? [], [$fee]);
            }
        }
    }

    /**
     * @return list<Fee>|null null when the aggregation or charge model failed
     */
    private function computeFees(MeteredItem $meteredItem, BaseResult $result): ?array
    {
        $aggregationResult = $this->aggregator($meteredItem)->aggregate();

        $chargeModelResult = $this->applyChargeModel($aggregationResult, $meteredItem, $result);

        if ($chargeModelResult === null) {
            return null;
        }

        $this->persistRecurringValue($aggregationResult, $meteredItem, $result);

        $chargeFees = $this->feesFromChargeModelResult($chargeModelResult, $meteredItem, $result);

        if ($this->skipCachingOfNonPersistableFee()) {
            $chargeFees = array_values(array_filter(
                $chargeFees,
                fn (Fee $fee) => $this->shouldPersistFee($fee, $chargeFees),
            ));
        }

        return $chargeFees;
    }

    private function skipCachingOfNonPersistableFee(): bool
    {
        return $this->options()->currentUsage();
    }

    /** @return list<Fee> */
    private function hydrateNonPersistableFees(MeteredItem $meteredItem): array
    {
        $zeroAggregation = $this->aggregator($meteredItem)->emptyResults();

        $chargeModelResult = ChargeModelFactory::newInstance(
            pricingStructure: $meteredItem->pricingStructure(),
            aggregationResult: $zeroAggregation,
            periodRatio: $meteredItem->periodRatio(),
            calculateProjectedUsage: $this->options()->calculateProjectedUsage,
        )->apply();

        return $this->feesFromChargeModelResult($chargeModelResult, $meteredItem, new BaseResult([]));
    }

    /** @return list<Fee> */
    private function feesFromChargeModelResult(
        \App\Services\ChargeModels\ChargeModelResult $chargeModelResult,
        MeteredItem $meteredItem,
        BaseResult $result,
    ): array {
        $fees = [];

        foreach ($chargeModelResult->groupedResults as $amountResult) {
            if ($this->options()->currentUsage()
                && MoneyMath::compare((string) $amountResult->units, '0') === 0
                && ! $this->options()->withZeroUnitsFilters) {
                continue;
            }

            $fee = $this->initFee($amountResult, $meteredItem, $result);

            if ($fee === null) {
                continue;
            }

            $fees[] = $fee;
        }

        return $fees;
    }

    private function initFee(
        \App\Services\ChargeModels\ChargeModelResult $amountResult,
        MeteredItem $meteredItem,
        BaseResult $result,
    ): ?Fee {
        // Prevent creating a fee with negative units or amount.
        if (MoneyMath::compare((string) $amountResult->units, '0') < 0
            || MoneyMath::compare((string) $amountResult->amount, '0') < 0) {
            $amountResult->setAmount('0');
            $amountResult->setUnitAmount('0');
            $amountResult->setUnits('0');
            $amountResult->setFullUnitsNumber('0');
        }

        // NOTE: amount_result is a precise decimal; round it to the currency
        // decimals and transform into currency cents.
        $roundedAmount = MoneyMath::roundTo((string) $amountResult->amount, $meteredItem->currencyExponent());
        $amountCents = MoneyMath::mul($roundedAmount, (string) $meteredItem->subunitToUnit());
        $preciseAmountCents = MoneyMath::mul((string) $amountResult->amount, (string) $meteredItem->subunitToUnit());
        $unitAmountCents = MoneyMath::mul((string) $amountResult->unitAmount, (string) $meteredItem->subunitToUnit());
        $preciseUnitAmount = (string) $amountResult->unitAmount;

        $units = $this->feeUnits($amountResult, $meteredItem);

        $newFee = new Fee([
            'invoice_id' => $this->invoice?->id,
            'organization_id' => $this->subscription->organization_id,
            'billing_entity_id' => $this->subscription->billing_entity_id ?? $this->subscription->customer?->billing_entity_id,
            'subscription_id' => $this->subscription->id,
            'charge_id' => $meteredItem->chargeId(),
            'amount_cents' => MoneyMath::round($amountCents),
            'precise_amount_cents' => $preciseAmountCents,
            'amount_currency' => $meteredItem->currency(),
            'fee_type' => FeeType::Charge,
            'invoiceable_type' => 'Charge',
            'invoiceable_id' => $meteredItem->chargeId(),
            'units' => $units,
            'total_aggregated_units' => $amountResult->totalAggregatedUnits ?? $units,
            'properties' => $meteredItem->filteredForChargeBoundaries(),
            'events_count' => $amountResult->count,
            'payment_status' => FeePaymentStatus::Pending,
            'taxes_amount_cents' => 0,
            'taxes_precise_amount_cents' => '0',
            'unit_amount_cents' => MoneyMath::round($unitAmountCents),
            'precise_unit_amount' => $preciseUnitAmount,
            'amount_details' => $this->serializeAmountDetails($amountResult->amountDetails),
            'grouped_by' => $amountResult->groupedBy ?: [],
        ]);

        if (! $meteredItem->invoiceable()) {
            $newFee->pay_in_advance = $meteredItem->payInAdvance();
        }

        if ($this->options()->applyTaxes) {
            $taxesResult = ApplyTaxesService::call(
                fee: $newFee,
                plan: $this->plan ?? $this->subscription->plan,
                customer: $this->customer ?? $this->subscription->customer,
            );

            try {
                $taxesResult->raiseIfError();
            } catch (\App\Services\Failures\FailedResult $e) {
                $result->failWithError($e);

                return null;
            }
        }

        return $newFee;
    }

    /**
     * Port of the jsonb write of `amount_details`: Rails stores the charge
     * models' BigDecimal values via ActiveSupport's `to_s("F")` (fixed
     * notation, trailing fractional zeros trimmed, always one decimal —
     * "125.000000000000000" -> "125.0"), while integers (event counts,
     * range bounds, per_package_size) pass through untouched.
     */
    private function serializeAmountDetails(mixed $details): mixed
    {
        if (is_array($details)) {
            return array_map($this->serializeAmountDetails(...), $details);
        }

        if (is_string($details) && is_numeric($details)) {
            return MoneyMath::toF($details);
        }

        return $details;
    }

    private function feeUnits(\App\Services\ChargeModels\ChargeModelResult $amountResult,
        MeteredItem $meteredItem,
    ): string {
        if ($this->options()->currentUsage() && ($meteredItem->payInAdvance() || $meteredItem->prorated())) {
            return (string) ($amountResult->currentUsageUnits ?? $amountResult->units);
        }

        if ($meteredItem->prorated()) {
            return $amountResult->fullUnitsNumber ?? $amountResult->units;
        }

        return (string) $amountResult->units;
    }

    private function shouldPersistFee(Fee $fee, array $fees): bool
    {
        if ($this->options()->recurring()) {
            return true;
        }

        if ((int) $fee->units !== 0 && MoneyMath::compare((string) $fee->units, '0') !== 0) {
            return true;
        }

        if ((int) $fee->amount_cents !== 0 || (int) $fee->events_count !== 0) {
            return true;
        }

        if ($fee->true_up_parent_fee_id !== null) {
            return true;
        }

        return collect($fees)->contains(fn (Fee $f) => $f->true_up_parent_fee_id === $fee->id);
    }

    private function initTrueUpFee(BaseResult $result): void
    {
        $fee = collect($result->fees ?? [])->first(fn (Fee $f) => $f->charge_filter_id === null);

        $usedAmountCents = (int) collect($result->fees ?? [])->sum(fn (Fee $f) => (int) $f->amount_cents);
        $usedPreciseAmountCents = (string) collect($result->fees ?? [])->reduce(
            fn (string $carry, Fee $f) => MoneyMath::add($carry, (string) $f->precise_amount_cents),
            '0',
        );

        $trueUpResult = CreateTrueUpService::call(
            fee: $fee,
            usedAmountCents: $usedAmountCents,
            usedPreciseAmountCents: $usedPreciseAmountCents,
        );

        if ($trueUpResult->true_up_fee !== null) {
            $result->fees = array_merge($result->fees ?? [], [$trueUpResult->true_up_fee]);
        }
    }

    private function applyChargeModel(
        AggregationResult $aggregationResult,
        MeteredItem $meteredItem,
        BaseResult $result,
    ): ?\App\Services\ChargeModels\ChargeModelResult {
        try {
            return ChargeModelFactory::newInstance(
                pricingStructure: $meteredItem->pricingStructure(),
                aggregationResult: $aggregationResult,
                periodRatio: $meteredItem->periodRatio(),
                calculateProjectedUsage: $this->options()->calculateProjectedUsage,
            )->apply();
        } catch (NotImplementedException $e) {
            $result->serviceFailure('charge_model_not_implemented', $e->getMessage(), $e);

            return null;
        }
    }

    private function alreadyBilled(BaseResult $result): bool
    {
        $existingFees = $this->invoice !== null
            ? $this->invoice->fees()
                ->where('charge_id', $this->meteredItem->chargeId())
                ->where('subscription_id', $this->subscription->id)
                ->get()
            : Fee::query()
                ->where('charge_id', $this->meteredItem->chargeId())
                ->where('subscription_id', $this->subscription->id)
                ->whereNull('invoice_id')
                ->whereNull('pay_in_advance_event_id')
                ->whereRaw("properties->>'charges_from_datetime' = ?", [
                    $this->isoMillis($this->meteredItem->boundaries->chargesFromDatetime),
                ])
                ->whereRaw("properties->>'charges_to_datetime' = ?", [
                    $this->isoMillis($this->meteredItem->boundaries->chargesToDatetime),
                ])
                ->get();

        if ($existingFees->isEmpty()) {
            return false;
        }

        $result->fees = $existingFees->all();

        return true;
    }

    private function aggregator(MeteredItem $meteredItem): Aggregator
    {
        return new Aggregator($meteredItem, $this->subscription, $this->options());
    }

    /**
     * NOTE: persist the current recurring value for the next period — only
     * weighted-sum and custom aggregations set `recurring_updated_at`.
     */
    private function persistRecurringValue(
        AggregationResult $aggregationResult,
        MeteredItem $meteredItem,
        BaseResult $result,
    ): void {
        if ($this->options()->currentUsage()) {
            return;
        }

        if ($aggregationResult->recurringUpdatedAt === null) {
            return;
        }

        $result->cached_aggregations ??= [];

        $existing = CachedAggregation::query()
            ->where('organization_id', $meteredItem->organizationId())
            ->where('external_subscription_id', $this->subscription->external_id)
            ->where('charge_id', $meteredItem->chargeId())
            ->whereNull('charge_filter_id')
            ->where('grouped_by', '{}')
            ->where('timestamp', $aggregationResult->recurringUpdatedAt)
            ->first();

        $aggregation = $existing ?? new CachedAggregation([
            'organization_id' => $meteredItem->organizationId(),
            'external_subscription_id' => $this->subscription->external_id,
            'charge_id' => $meteredItem->chargeId(),
            'grouped_by' => [],
            'timestamp' => $aggregationResult->recurringUpdatedAt,
        ]);

        $aggregation->current_aggregation = $aggregationResult->totalAggregatedUnits ?? $aggregationResult->units();
        $aggregation->current_amount = $aggregationResult->customAggregation['amount'] ?? null;
        $aggregation->save();

        $result->cached_aggregations[] = $aggregation;
    }

    /** Rails `iso8601(3)` — millisecond precision, UTC. */
    private function isoMillis(mixed $datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }

        return \App\Support\Utils\Datetime::parseIso8601($datetime)?->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
