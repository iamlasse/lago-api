<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Enums\FeeType;
use App\Enums\FeePaymentStatus;
use App\Models\AdjustedFee;
use App\Models\BillingPeriodBoundaries;
use App\Models\Fee;
use App\Models\FixedCharge;
use App\Support\Currency;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\ChargeModels\AggregationResult;
use App\Services\ChargeModels\ChargeModelResult;
use App\Services\ChargeModels\Factory as ChargeModelFactory;
use App\Services\ChargeModels\PricingStructure;

/**
 * Port of Rails' Fees::InitFromAdjustedFixedChargeFeeService
 * (app/services/fees/init_from_adjusted_fixed_charge_fee_service.rb): the
 * fixed-charge twin of InitFromAdjustedChargeFeeService.
 */
class InitFromAdjustedFixedChargeFeeService extends BaseService
{
    private ?ChargeModelResult $amountResult = null;

    public function __construct(
        private readonly AdjustedFee $adjustedFee,
        private readonly BillingPeriodBoundaries $boundaries,
        private readonly array $properties,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fee');

        // NOTE: Rails guards `adjusted_units? && amount_result.failure?` — the
        // local charge models never fail on a plain aggregation.

        $result->fee = $this->initAdjustedFee();

        return $result;
    }

    private function initAdjustedFee(): Fee
    {
        $adjustedFee = $this->adjustedFee;
        $invoice = $adjustedFee->invoice;
        $fixedCharge = $this->fixedCharge();
        $currency = (string) $invoice->currency;
        $subunit = (string) Currency::subunitToUnit($currency);
        $units = (string) $adjustedFee->units;

        $amountDetails = [];

        if ($adjustedFee->adjusted_units) {
            $amountResult = $this->amountResult();

            $roundedAmount = MoneyMath::roundTo((string) $amountResult->amount, Currency::exponent($currency));
            $preciseAmountCents = MoneyMath::mul((string) $amountResult->amount, $subunit);
            $amountCents = MoneyMath::mul($roundedAmount, $subunit);
            $unitAmountCents = MoneyMath::mul((string) $amountResult->unitAmount, $subunit);
            $preciseUnitAmount = (string) $amountResult->unitAmount;
            $amountDetails = $amountResult->amountDetails;
        } else {
            $unitPreciseAmountCents = (string) $adjustedFee->unit_precise_amount_cents;

            $unitAmountCents = MoneyMath::round($unitPreciseAmountCents);
            $preciseAmountCents = MoneyMath::mul($units, $unitPreciseAmountCents);
            $amountCents = MoneyMath::round($preciseAmountCents);
            $preciseUnitAmount = MoneyMath::fdiv($unitPreciseAmountCents, $subunit);
        }

        return new Fee([
            'invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
            'billing_entity_id' => $invoice->billing_entity_id,
            'subscription_id' => $adjustedFee->subscription_id,
            'fixed_charge_id' => $fixedCharge?->id,
            'amount_cents' => (int) MoneyMath::round($amountCents),
            'precise_amount_cents' => $preciseAmountCents,
            'amount_currency' => $currency,
            'fee_type' => FeeType::FixedCharge,
            'invoiceable_type' => 'FixedCharge',
            'invoiceable_id' => $fixedCharge?->id,
            'units' => $units,
            'total_aggregated_units' => $units,
            'properties' => $this->boundaries->toArray(),
            'events_count' => 0,
            'payment_status' => FeePaymentStatus::Pending,
            'taxes_amount_cents' => 0,
            'taxes_precise_amount_cents' => '0',
            'unit_amount_cents' => (int) MoneyMath::round($unitAmountCents),
            'precise_unit_amount' => $preciseUnitAmount,
            'amount_details' => $this->serializeAmountDetails($amountDetails),
            'invoice_display_name' => $adjustedFee->invoice_display_name,
        ]);
    }

    /**
     * Rails re-prices the adjusted units through the fixed charge's model;
     * PricingStructure::fromFixedCharge carries the same properties Rails'
     * `.with(properties:)` override would.
     */
    private function amountResult(): ChargeModelResult
    {
        if ($this->amountResult !== null) {
            return $this->amountResult;
        }

        $adjustedFee = $this->adjustedFee;

        $aggregationResult = new AggregationResult(
            aggregation: (string) $adjustedFee->units,
            currentUsageUnits: (string) $adjustedFee->units,
            fullUnitsNumber: (string) $adjustedFee->units,
            count: 0,
        );

        return $this->amountResult = ChargeModelFactory::newInstance(
            pricingStructure: PricingStructure::fromFixedCharge($this->fixedCharge()),
            aggregationResult: $aggregationResult,
            periodRatio: 1.0,
            calculateProjectedUsage: false,
        )->apply();
    }

    /** Rails: `fixed_charge` — falls back to the discarded fixed charge for regenerated invoices. */
    private function fixedCharge(): ?FixedCharge
    {
        $adjustedFee = $this->adjustedFee;

        if ($adjustedFee->fixed_charge_id !== null && $adjustedFee->fixedCharge !== null) {
            return $adjustedFee->fixedCharge;
        }

        if ($adjustedFee->invoice->voided_invoice_id !== null && $adjustedFee->fixed_charge_id !== null) {
            /** @var FixedCharge|null */
            return FixedCharge::withTrashed()->find($adjustedFee->fixed_charge_id);
        }

        return null;
    }

    /** Port of ChargeService's amount_details cleanup — decimal strings to floats. */
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
}
