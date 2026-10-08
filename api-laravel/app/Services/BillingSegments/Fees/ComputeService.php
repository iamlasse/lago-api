<?php

declare(strict_types=1);

namespace App\Services\BillingSegments\Fees;

use App\Models\Fee;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\Fees\TrueUp;
use App\Models\BillingSegment;
use App\Services\Fees\AmountsService;
use App\Services\ChargeModels\Factory;
use App\Services\Fees\AppliedPricingUnit;
use App\Services\ChargeModels\PricingStructure;
use App\Services\ChargeModels\AggregationResult;

/**
 * Port of Rails' BillingSegments::Fees::ComputeService
 * (app/services/billing_segments/fees/compute_service.rb) — prices a FIXED
 * product's stored segment: the card's units over the segment window, with
 * the snapshotted rate, the segment's proration and the prorated minimum as
 * a true-up.
 */
class ComputeService extends BaseService
{
    public function __construct(
        private readonly BillingSegment $billingSegment,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('fee', 'true_up_fee');

        $rate = $this->billingSegment->rate();

        if ($rate === null) {
            return $result->notFoundFailure(resource: 'rate');
        }

        $amounts = AmountsService::build(
            currency: $this->billingSegment->currency,
            chargeModelResult: $this->chargeModelResult(),
            appliedPricingUnit: $this->appliedPricingUnit(),
            trueUp: new TrueUp(
                minimumAmountCents: $this->trueUpMinimumCents(),
            ),
        )->raiseIfError();

        $amount = $amounts->amount;
        $trueUpAmount = $amounts->true_up_amount;

        $result->fee = $this->fee($amount, $this->chargeModelResult()->amountDetails);

        $result->true_up_fee = $trueUpAmount !== null
            ? $this->trueUpFee($result->fee, $trueUpAmount, $this->chargeModelResult()->amountDetails)
            : null;

        return $result;
    }

    /** Rails: `#fee` — the service-price product fee, unsaved. */
    private function fee(object $amount, array $amountDetails): Fee
    {
        $contractRateCard = $this->billingSegment->contractRateCard;
        $product = $contractRateCard->rateCard->product;

        $fee = new Fee;
        $fee->forceFill([
            'organization_id' => $this->billingSegment->organization_id,
            'contract_id' => $this->billingSegment->contract_id,
            'contract_rate_card_id' => $contractRateCard->id,
            'invoiceable_type' => $product->getMorphClass(),
            'invoiceable_id' => $product->id,
            'fee_type' => \App\Enums\FeeType::Product->value,
            'rate_card_rate_id' => $this->billingSegment->rate_card_rate_id,
            'rate_override_id' => $this->billingSegment->rate_override_id,
            'amount_cents' => $amount->amountCents,
            'amount_currency' => $this->billingSegment->currency,
            'unit_amount_cents' => $amount->unitAmountCents,
            'precise_unit_amount' => $amount->preciseUnitAmount,
            'units' => $this->units(),
            'taxes_amount_cents' => 0,
            'precise_amount_cents' => $amount->preciseAmountCents,
            'amount_details' => $amountDetails,
            'properties' => $this->boundaries($this->billingSegment->id),
        ]);

        return $fee;
    }

    /**
     * Rails: `#true_up_fee` — the prorated minimum, attached to its parent.
     * Rails associates the parent as an OBJECT (the fee is unsaved, so no id
     * yet); the caller resolves the FK after saving the parent.
     */
    private function trueUpFee(Fee $fee, object $trueUpAmount, array $amountDetails): Fee
    {
        $trueUpFee = $fee->replicate();

        $trueUpFee->forceFill([
            'amount_cents' => $trueUpAmount->amountCents,
            'precise_amount_cents' => $trueUpAmount->preciseAmountCents,
            'units' => 1,
            'unit_amount_cents' => $trueUpAmount->unitAmountCents,
            'precise_unit_amount' => $trueUpAmount->preciseUnitAmount,
        ]);

        return $trueUpFee;
    }

    /** Rails: `#charge_model_result` — the card's units priced by the snapshotted rate. */
    private function chargeModelResult(): \App\Services\ChargeModels\ChargeModelResult
    {
        return Factory::newInstance(
            pricingStructure: PricingStructure::fromBillingSegment($this->billingSegment),
            aggregationResult: $this->aggregationResult(),
            periodRatio: $this->billingSegment->elapsedPeriodRatio(),
            calculateProjectedUsage: false,
        )->apply();
    }

    /**
     * Rails: `#aggregation_result` — the fixed product's units prorated by
     * the stored service-price ratio, shaped like an aggregation outcome.
     */
    private function aggregationResult(): AggregationResult
    {
        $units = $this->units();
        $proratedUnits = MoneyMath::mul($units, (string) $this->billingSegment->proration_ratio);

        return new AggregationResult(
            aggregation: $proratedUnits,
            currentUsageUnits: $proratedUnits,
            fullUnitsNumber: $units,
            count: 1,
            preciseTotalAmountCents: '0',
            options: ['running_total' => []],
        );
    }

    /**
     * Rails: `#boundaries` — the fee `properties` hash carrying the segment
     * window and its own id.
     *
     * @return array<string, mixed>
     */
    private function boundaries(string $billingSegmentId): array
    {
        return [
            'from_datetime' => $this->billingSegment->started_at,
            'to_datetime' => $this->billingSegment->ended_at,
            'charges_from_datetime' => $this->billingSegment->started_at,
            'charges_to_datetime' => $this->billingSegment->ended_at,
            'charges_duration' => $this->billingSegment->durationInDays(),
            'timestamp' => $this->billingSegment->billing_at,
            'billing_segment_id' => $billingSegmentId,
        ];
    }

    private function appliedPricingUnit(): AppliedPricingUnit
    {
        return AppliedPricingUnit::fromPricingUnit(
            pricingUnit: $this->billingSegment->pricingUnit,
            conversionRate: $this->billingSegment->pricingUnitConversionRate(),
        );
    }

    /**
     * Rails: `minimum_amount_cents: billing_segment.prorated_min_amount_cents`
     * — a BigDecimal. Laravel's TrueUp option carries an integer; the
     * fractional fiat minimum rounds here.
     *
     * TODO(port): when a pricing unit is configured Rails passes the minimum
     * in pricing-UNIT cents and AmountsService converts it back out; the
     * Laravel AmountsService keeps `pricing_unit_usage` and the unit-cents
     * true-up domain unported, so the fiat minimum rides through. Same edge
     * the AppliedPricingUnit TODO elsewhere carries.
     */
    private function trueUpMinimumCents(): int
    {
        return MoneyMath::round($this->billingSegment->proratedMinAmountCents());
    }

    /** Rails: `#units` — the contract card's units, zero when unset. */
    private function units(): string
    {
        return (string) ($this->billingSegment->contractRateCard->units ?? 0);
    }
}
