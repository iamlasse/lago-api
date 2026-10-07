<?php

declare(strict_types=1);

namespace App\Services\AdjustedFees;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Charge;
use App\Models\Invoice;
use App\Support\License;
use App\Models\AdjustedFee;
use App\Models\Subscription;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\FeePaymentStatus;
use App\Services\Failures\FailedResult;
use App\Services\Invoices\RefreshDraftService;

/**
 * Port of Rails' AdjustedFees::CreateService
 * (app/services/adjusted_fees/create_service.rb) — creates (or matches) the
 * fee to adjust on a draft invoice and persists the AdjustedFee override;
 * the draft invoice is then refreshed so the fee picks the adjustment up.
 *
 * regeneratingVoided — used when regenerating fees from a voided invoice
 * into a new invoice (Invoices::RegenerateFromVoidedService); if true, skips
 * refreshing the draft invoice and the license check.
 */
class CreateService extends BaseService
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly Invoice $invoice,
        private readonly array $params,
        private readonly bool $regeneratingVoided = false,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('fee', 'adjusted_fee');

        try {
            if ($this->forbidden()) {
                return $result->forbiddenFailure();
            }

            $fee = $this->findOrCreateFee($result);

            if ($result->failure()) {
                return $result;
            }

            if ($fee->adjustedFee !== null) {
                return $result->validationFailure(['adjusted_fee' => ['already_exists']]);
            }

            $charge = $fee->charge;

            if ($this->disabledChargeModel($charge)) {
                return $result->validationFailure(['charge' => ['invalid_charge_model']]);
            }

            $unitPreciseAmountCents = isset($this->params['unit_precise_amount'])
                ? (string) ((float) $this->params['unit_precise_amount']
                    * \App\Support\Currency::subunitToUnit((string) $fee->amount_currency))
                : 0.0;

            $adjustedFee = new AdjustedFee([
                'fee_id' => $fee->id,
                'invoice_id' => $fee->invoice_id,
                'subscription_id' => $fee->subscription_id,
                'charge_id' => $fee->charge_id,
                'fixed_charge_id' => $fee->fixed_charge_id,
                'adjusted_units' => $this->hasUnits() && ! isset($this->params['unit_precise_amount']),
                'adjusted_amount' => $this->hasUnits() && isset($this->params['unit_precise_amount']),
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
            $adjustedFee->save();

            if (! $this->regeneratingVoided) {
                RefreshDraftService::call(invoice: $this->invoice)->raiseIfError();
            }

            $result->adjusted_fee = $adjustedFee->refresh();
            // Rails: invoice.fees.find_by(subscription_id:, fixed_charge_id:)
            // / find_by(subscription_id:, charge_id:, charge_filter_id:) —
            // find_by matches NULL columns, a plain SQL `=` never does.
            $nullAware = function ($query, string $column, ?string $value): void {
                $value === null
                    ? $query->whereNull($column)
                    : $query->where($column, $value);
            };

            $result->fee = $this->invoice->fees()
                ->where('subscription_id', $fee->subscription_id)
                ->where(function ($query) use ($fee, $nullAware): void {
                    if ($fee->fixed_charge_id !== null) {
                        $nullAware($query, 'fixed_charge_id', $fee->fixed_charge_id);
                    } else {
                        $nullAware($query, 'charge_id', $fee->charge_id);
                        $nullAware($query, 'charge_filter_id', $fee->charge_filter_id);
                    }
                })
                ->first();

            return $result;
        } catch (FailedResult $e) {
            return $result->failWithError($e);
        }
    }

    private function hasUnits(): bool
    {
        return ($this->params['units'] ?? null) !== null
            && ($this->params['units'] ?? null) !== '';
    }

    private function subscription(): ?Subscription
    {
        return $this->invoice->subscriptions()
            ->where('subscriptions.id', $this->params['subscription_id'] ?? null)
            ->first();
    }

    private function forbidden(): bool
    {
        if ($this->regeneratingVoided) {
            return false;
        }

        return ! License::premium() || ! $this->invoice->isDraft();
    }

    private function findOrCreateFee(BaseResult $result): Fee
    {
        if (array_key_exists('fee_id', $this->params)) {
            return $this->findExistingFee($result);
        }

        return $this->createEmptyFee($result);
    }

    private function findExistingFee(BaseResult $result): Fee
    {
        $fee = $this->invoice->fees()->firstWhere('id', $this->params['fee_id'] ?? null);

        if ($fee === null) {
            // Rails: result.not_found_failure!(resource: "fee") — surfaces as
            // a FailedResult through call's rescue-free flow.
            $result->notFoundFailure('fee')->raiseIfError();
        }

        return $fee;
    }

    private function createEmptyFee(BaseResult $result): Fee
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            $result->notFoundFailure('subscription')->raiseIfError();
        }

        if (($this->params['fixed_charge_id'] ?? null) !== null) {
            return $this->createEmptyFeeForFixedCharge($result, $subscription);
        }

        $charge = $subscription->plan->charges()->firstWhere('id', $this->params['charge_id'] ?? null);

        if ($charge === null) {
            $result->notFoundFailure('charge')->raiseIfError();
        }

        $chargeFilter = null;
        if (($this->params['charge_filter_id'] ?? null) !== null) {
            $chargeFilter = $charge->filters()->firstWhere('id', $this->params['charge_filter_id']);

            if ($chargeFilter === null) {
                $result->notFoundFailure('charge_filter')->raiseIfError();
            }
        }

        $chargeFilterId = $this->params['charge_filter_id'] ?? null;

        $fee = $this->invoice->fees()
            ->where('subscription_id', $subscription->id)
            ->where('charge_id', $charge->id)
            ->where(function ($query) use ($chargeFilterId): void {
                $chargeFilterId === null
                    ? $query->whereNull('charge_filter_id')
                    : $query->where('charge_filter_id', $chargeFilterId);
            })
            ->first();

        return $fee ?? $this->createFee($subscription, $charge, FeeType::Charge);
    }

    private function createEmptyFeeForFixedCharge(BaseResult $result, Subscription $subscription): Fee
    {
        $fixedCharge = $subscription->plan->fixedCharges()->firstWhere('id', $this->params['fixed_charge_id']);

        if ($fixedCharge === null) {
            $result->notFoundFailure('fixed_charge')->raiseIfError();
        }

        $fee = $this->invoice->fees()
            ->where('subscription_id', $subscription->id)
            ->where('fixed_charge_id', $fixedCharge->id)
            ->first();

        return $fee ?? $this->createFee($subscription, $fixedCharge, FeeType::FixedCharge);
    }

    private function createFee(Subscription $subscription, object $chargeable, FeeType $feeType): Fee
    {
        $invoiceSubscription = $this->invoice->invoiceSubscriptions()
            ->firstWhere('subscription_id', $subscription->id);

        $properties = ['timestamp' => $invoiceSubscription?->timestamp];

        if ($feeType === FeeType::Charge) {
            $properties['charges_from_datetime'] = $invoiceSubscription?->charges_from_datetime;
            $properties['charges_to_datetime'] = $invoiceSubscription?->charges_to_datetime;
        } else {
            $properties['fixed_charges_from_datetime'] = $invoiceSubscription?->fixed_charges_from_datetime;
            $properties['fixed_charges_to_datetime'] = $invoiceSubscription?->fixed_charges_to_datetime;
        }

        return Fee::query()->create([
            'organization_id' => $this->invoice->organization_id,
            'billing_entity_id' => $this->invoice->billing_entity_id,
            'invoice_id' => $this->invoice->id,
            'subscription_id' => $subscription->id,
            'invoiceable_type' => $feeType === FeeType::Charge ? 'Charge' : 'FixedCharge',
            'invoiceable_id' => $chargeable->id,
            'charge_id' => $feeType === FeeType::Charge ? $chargeable->id : null,
            'fixed_charge_id' => $feeType === FeeType::FixedCharge ? $chargeable->id : null,
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
            'properties' => $properties,
        ]);
    }

    private function disabledChargeModel(?Charge $charge): bool
    {
        if ($charge === null) {
            return false;
        }

        if (! $this->unitAdjustment()) {
            return false;
        }

        return $charge->percentage() || ($charge->proratedCharge() && $charge->graduated());
    }

    private function unitAdjustment(): bool
    {
        return $this->hasUnits() && ! isset($this->params['unit_precise_amount']);
    }
}
