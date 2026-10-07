<?php

declare(strict_types=1);

namespace App\Services\Fees;

use App\Enums\FeeType;
use App\Enums\FeePaymentStatus;
use App\Models\AdjustedFee;
use App\Models\BillingPeriodBoundaries;
use App\Models\Charge;
use App\Models\ChargeFilter;
use App\Models\Fee;
use App\Support\Currency;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Services\ChargeModels\AggregationResult;
use App\Services\ChargeModels\ChargeModelResult;
use App\Services\ChargeModels\Factory as ChargeModelFactory;
use App\Services\ChargeModels\PricingStructure;

/**
 * Port of Rails' Fees::InitFromAdjustedChargeFeeService
 * (app/services/fees/init_from_adjusted_charge_fee_service.rb): rebuilds an
 * in-memory charge fee from an AdjustedFee — either the units were adjusted
 * (re-price `units` through the charge model with the stored boundaries) or
 * the precise unit amount was (amount = units × stored unit amount).
 */
class InitFromAdjustedChargeFeeService extends BaseService
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
        // local charge models never fail on a plain aggregation (no per-event
        // store dependency), so there is nothing to fail with here.

        $result->fee = $this->initAdjustedFee();

        return $result;
    }

    private function initAdjustedFee(): Fee
    {
        $adjustedFee = $this->adjustedFee;
        $invoice = $adjustedFee->invoice;
        $charge = $this->charge();
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
            'charge_id' => $charge?->id,
            'amount_cents' => (int) MoneyMath::round($amountCents),
            'precise_amount_cents' => $preciseAmountCents,
            'amount_currency' => $currency,
            'fee_type' => FeeType::Charge,
            'invoiceable_type' => 'Charge',
            'invoiceable_id' => $charge?->id,
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
            'grouped_by' => $adjustedFee->grouped_by ?? [],
            'charge_filter_id' => $this->chargeFilter()?->id,
        ]);
    }

    /**
     * Rails: the adjusted units are re-priced through the charge model with
     * the stored properties. Rails' `.with(properties:)` override carries the
     * same payload PricingStructure::fromCharge reads, so the charge's own
     * properties are used here.
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
            preciseTotalAmountCents: $this->charge()?->dynamic() ? '0' : null,
        );

        return $this->amountResult = ChargeModelFactory::newInstance(
            pricingStructure: $this->pricingStructure(),
            aggregationResult: $aggregationResult,
            periodRatio: 1.0,
            calculateProjectedUsage: false,
        )->apply();
    }

    /**
     * Rails: `ChargeModels::PricingStructure.from_charge(charge).with(properties:)`.
     * The port's PricingStructure always reads the charge's properties — the
     * dynamic-charge precise total override (precise_total_amount_cents = 0)
     * is applied through the aggregation when the charge is dynamic.
     */
    private function pricingStructure(): PricingStructure
    {
        return PricingStructure::fromCharge($this->charge());
    }

    /** Rails: `charge` — falls back to the discarded charge for regenerated invoices. */
    private function charge(): ?Charge
    {
        $adjustedFee = $this->adjustedFee;

        if ($adjustedFee->charge_id !== null && $adjustedFee->charge !== null) {
            return $adjustedFee->charge;
        }

        if ($adjustedFee->invoice->voided_invoice_id !== null && $adjustedFee->charge_id !== null) {
            /** @var Charge|null */
            return Charge::withTrashed()->find($adjustedFee->charge_id);
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

    /** Rails: `charge_filter` — falls back to the discarded filter for regenerated invoices. */
    private function chargeFilter(): ?ChargeFilter
    {
        $adjustedFee = $this->adjustedFee;

        if ($adjustedFee->charge_filter_id !== null && $adjustedFee->chargeFilter !== null) {
            return $adjustedFee->chargeFilter;
        }

        if ($adjustedFee->invoice->voided_invoice_id !== null && $adjustedFee->charge_filter_id !== null) {
            /** @var ChargeFilter|null */
            return ChargeFilter::withTrashed()->find($adjustedFee->charge_filter_id);
        }

        return null;
    }
}
