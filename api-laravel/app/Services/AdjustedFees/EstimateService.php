<?php

declare(strict_types=1);

namespace App\Services\AdjustedFees;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Charge;
use App\Models\Invoice;
use App\Support\Currency;
use App\Support\MoneyMath;
use App\Models\AdjustedFee;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\FeePaymentStatus;
use App\Models\BillingPeriodBoundaries;
use App\Services\Failures\FailedResult;
use App\Services\Fees\ApplyTaxesService;
use App\Services\Fees\InitFromAdjustedChargeFeeService;
use App\Services\Fees\InitFromAdjustedFixedChargeFeeService;

/**
 * Port of Rails' AdjustedFees::EstimateService
 * (app/services/adjusted_fees/estimate_service.rb) — the PreviewAdjustedFee
 * mutation: returns the fee the adjustment WOULD produce, without persisting
 * anything (the returned fee / applied taxes carry throwaway ids).
 */
class EstimateService extends BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly Invoice $invoice,
        private readonly array $params,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fee', 'adjusted_fee');

        try {
            $fee = $this->findOrInitializeFee($result);

            if ($result->failure()) {
                return $result;
            }

            if ($this->disabledChargeModel($fee->charge)) {
                return $result->validationFailure(['charge' => ['invalid_charge_model']]);
            }

            $adjustedFee = $this->initializeAdjustedFee($fee);

            $estimatedFee = match (true) {
                $fee->fee_type === FeeType::Subscription => $this->adjustSubscriptionFee($fee, $adjustedFee),
                $fee->fee_type === FeeType::FixedCharge => $this->initFromFixedChargeFee($adjustedFee),
                default => $this->initFromChargeFee($adjustedFee),
            };

            // Rails: fee.presentation_breakdowns = [] (PresentationBreakdown
            // association) — TODO(port) with the breakdowns model; nothing to
            // reset on the in-memory fee yet.

            if ($this->invoice->customer->taxCustomer() === null) {
                // NOTE: Provider taxes don't apply in the estimate (same as
                // the preview) — local taxes only, with throwaway applied-tax
                // ids so they never collide with persisted rows.
                ApplyTaxesService::callBang(fee: $estimatedFee);
                foreach ($estimatedFee->appliedTaxes as $appliedTax) {
                    $appliedTax->id = \Illuminate\Support\Str::uuid()->toString();
                }
            }

            $result->fee = $estimatedFee;

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }

    private function initFromChargeFee(AdjustedFee $adjustedFee): Fee
    {
        $properties = $adjustedFee->chargeFilter?->properties ?? $adjustedFee->charge?->properties ?? [];

        $feeResult = InitFromAdjustedChargeFeeService::call(
            adjustedFee: $adjustedFee,
            boundaries: BillingPeriodBoundaries::fromProperties($adjustedFee->properties ?? []),
            properties: $properties,
        );

        $feeResult->raiseIfError();

        $feeResult->fee->id = \Illuminate\Support\Str::uuid()->toString();

        return $feeResult->fee;
    }

    private function initFromFixedChargeFee(AdjustedFee $adjustedFee): Fee
    {
        $feeResult = InitFromAdjustedFixedChargeFeeService::call(
            adjustedFee: $adjustedFee,
            boundaries: BillingPeriodBoundaries::fromProperties($adjustedFee->properties ?? []),
            properties: $adjustedFee->fixedCharge->properties ?? [],
        );

        $feeResult->raiseIfError();

        $feeResult->fee->id = \Illuminate\Support\Str::uuid()->toString();

        return $feeResult->fee;
    }

    private function adjustSubscriptionFee(Fee $fee, AdjustedFee $adjustedFee): Fee
    {
        if ($adjustedFee->adjustedDisplayName()) {
            $fee->invoice_display_name = $adjustedFee->invoice_display_name;

            return $fee;
        }

        $units = (string) $adjustedFee->units;
        $subunit = (string) Currency::subunitToUnit((string) $this->invoice->currency);

        if ($adjustedFee->adjusted_units) {
            $unitCents = (string) $fee->unit_amount_cents;
            $amountCents = MoneyMath::round(MoneyMath::mul($units, $unitCents));
            $preciseUnitAmount = MoneyMath::fdiv($unitCents, $subunit);
        } else {
            $unitCents = (string) $adjustedFee->unit_precise_amount_cents;
            $amountCents = MoneyMath::round(MoneyMath::mul($units, $unitCents));
            $preciseUnitAmount = MoneyMath::fdiv($unitCents, $subunit);
        }

        $fee->units = $units;
        $fee->unit_amount_cents = (int) MoneyMath::round($unitCents);
        $fee->precise_unit_amount = $preciseUnitAmount;
        $fee->amount_cents = (int) $amountCents;
        $fee->precise_amount_cents = MoneyMath::mul($units, $unitCents);

        if (($this->params['invoice_display_name'] ?? null) !== null) {
            $fee->invoice_display_name = $this->params['invoice_display_name'];
        }

        return $fee;
    }

    private function initializeAdjustedFee(Fee $fee): AdjustedFee
    {
        if (isset($this->params['unit_precise_amount'])) {
            $unitPreciseAmountCents = (string) ((float) $this->params['unit_precise_amount']
                * Currency::subunitToUnit((string) $fee->amount_currency));
        } else {
            $unitPreciseAmountCents = (string) $fee->precise_unit_amount;
        }

        return new AdjustedFee([
            'fee_id' => $fee->id,
            'invoice_id' => $fee->invoice_id,
            'subscription_id' => $fee->subscription_id,
            'charge_id' => $fee->charge_id,
            'fixed_charge_id' => $fee->fixed_charge_id,
            'adjusted_units' => ($this->params['units'] ?? null) !== null && ! isset($this->params['unit_precise_amount']),
            'adjusted_amount' => ($this->params['units'] ?? null) !== null && isset($this->params['unit_precise_amount']),
            'invoice_display_name' => $this->params['invoice_display_name'] ?? null,
            'fee_type' => $fee->fee_type,
            'properties' => $fee->properties ?? [],
            'units' => $this->params['units'] ?? 0,
            'unit_amount_cents' => (int) round((float) $unitPreciseAmountCents),
            'unit_precise_amount_cents' => $unitPreciseAmountCents,
            'grouped_by' => $fee->grouped_by ?? [],
            'charge_filter_id' => $fee->charge_filter_id,
            'organization_id' => $this->invoice->organization_id,
        ]);
    }

    // TODO: Consider if prorated fixed charges should also have
    // graduated charge model disabled when units are adjusted,
    // as is currently done for charges.
    private function disabledChargeModel(?Charge $charge): bool
    {
        if ($charge === null) {
            return false;
        }

        $unitAdjustment = ($this->params['units'] ?? null) !== null && ! isset($this->params['unit_precise_amount']);

        if (! $unitAdjustment) {
            return false;
        }

        return $charge->percentage() || ($charge->proratedCharge() && $charge->graduated());
    }

    private function findOrInitializeFee(BaseResult $result): Fee
    {
        if (array_key_exists('fee_id', $this->params)) {
            return $this->findExistingFee($result);
        }

        return $this->initializeFee($result);
    }

    private function findExistingFee(BaseResult $result): Fee
    {
        $fee = $this->invoice->fees()->firstWhere('id', $this->params['fee_id'] ?? null);

        if ($fee === null) {
            $result->notFoundFailure('fee')->raiseIfError();
        }

        return $fee;
    }

    private function initializeFee(BaseResult $result): Fee
    {
        if (($this->params['fixed_charge_id'] ?? null) !== null) {
            return $this->initializeFeeForFixedCharge($result);
        }

        $subscription = $this->invoice->subscriptions()
            ->firstWhere('id', $this->params['invoice_subscription_id'] ?? null);

        if ($subscription === null) {
            $result->notFoundFailure('subscription')->raiseIfError();
        }

        $charge = $subscription->plan->charges()->firstWhere('id', $this->params['charge_id'] ?? null);

        if ($charge === null) {
            $result->notFoundFailure('charge')->raiseIfError();
        }

        if (($this->params['charge_filter_id'] ?? null) !== null) {
            $chargeFilter = $charge->filters()->firstWhere('id', $this->params['charge_filter_id']);

            if ($chargeFilter === null) {
                $result->notFoundFailure('charge_filter')->raiseIfError();
            }
        }

        $fee = $this->findInvoiceFee($result, $subscription->id, $charge->id, $this->params['charge_filter_id'] ?? null);

        return $fee ?? $this->initializeEmptyFee($result, $subscription, $charge, FeeType::Charge);
    }

    private function initializeFeeForFixedCharge(BaseResult $result): Fee
    {
        $subscription = $this->invoice->subscriptions()
            ->firstWhere('id', $this->params['invoice_subscription_id'] ?? null);

        if ($subscription === null) {
            $result->notFoundFailure('subscription')->raiseIfError();
        }

        $fixedCharge = $subscription->plan->fixedCharges()->firstWhere('id', $this->params['fixed_charge_id']);

        if ($fixedCharge === null) {
            $result->notFoundFailure('fixed_charge')->raiseIfError();
        }

        $fee = $this->invoice->fees()
            ->where('subscription_id', $subscription->id)
            ->where('fixed_charge_id', $fixedCharge->id)
            ->first();

        return $fee ?? $this->initializeEmptyFee($result, $subscription, $fixedCharge, FeeType::FixedCharge);
    }

    /**
     * Rails: `invoice.fees.find_by(subscription_id:, charge_id:, charge_filter_id:)`
     * — find_by matches a NULL charge_filter_id, unlike a plain SQL `=`.
     */
    private function findInvoiceFee(BaseResult $result, string $subscriptionId, string $chargeId, ?string $chargeFilterId): ?Fee
    {
        return $this->invoice->fees()
            ->where('subscription_id', $subscriptionId)
            ->where('charge_id', $chargeId)
            ->where(function ($query) use ($chargeFilterId): void {
                $chargeFilterId === null
                    ? $query->whereNull('charge_filter_id')
                    : $query->where('charge_filter_id', $chargeFilterId);
            })
            ->first();
    }

    private function initializeEmptyFee(BaseResult $result, Subscription $subscription, object $invoiceable, FeeType $feeType): Fee
    {
        $invoiceSubscription = $this->invoice->invoiceSubscriptions()
            ->firstWhere('subscription_id', $subscription->id);

        $boundaries = new BillingPeriodBoundaries(
            fromDatetime: null,
            toDatetime: null,
            chargesFromDatetime: $invoiceSubscription?->charges_from_datetime,
            chargesToDatetime: $invoiceSubscription?->charges_to_datetime,
            chargesDuration: $invoiceSubscription?->charges_duration,
            timestamp: $invoiceSubscription?->timestamp,
            fixedChargesFromDatetime: $invoiceSubscription?->fixed_charges_from_datetime,
            fixedChargesToDatetime: $invoiceSubscription?->fixed_charges_to_datetime,
            fixedChargesDuration: $invoiceSubscription?->fixed_charges_duration,
        );

        return Fee::query()->make([
            'organization_id' => $this->invoice->organization_id,
            'billing_entity_id' => $this->invoice->billing_entity_id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $subscription->id,
            'invoiceable_type' => $feeType === FeeType::Charge ? 'Charge' : 'FixedCharge',
            'invoiceable_id' => $invoiceable->id,
            'charge_id' => $feeType === FeeType::Charge ? $invoiceable->id : null,
            'fixed_charge_id' => $feeType === FeeType::FixedCharge ? $invoiceable->id : null,
            'charge_filter_id' => $this->params['charge_filter_id'] ?? null,
            'grouped_by' => [],
            'fee_type' => $feeType,
            'payment_status' => FeePaymentStatus::Pending,
            'events_count' => 0,
            'amount_currency' => $this->invoice->currency,
            'amount_cents' => 0,
            'precise_amount_cents' => '0',
            'unit_amount_cents' => 0,
            'precise_unit_amount' => '0',
            'taxes_amount_cents' => 0,
            'taxes_precise_amount_cents' => '0',
            'units' => '0',
            'total_aggregated_units' => '0',
            'properties' => $boundaries->toArray(),
        ]);
    }
}
