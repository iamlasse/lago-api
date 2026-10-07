<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Fee;
use App\Enums\FeeType;
use App\Models\Invoice;
use App\Support\Currency;
use App\Support\MoneyMath;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Services\BaseService;
use Illuminate\Support\Carbon;
use App\Enums\FeePaymentStatus;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use App\Models\InvoiceSubscription;
use App\Models\AppliedUsageThreshold;
use App\Models\BillingPeriodBoundaries;
use App\Jobs\Invoices\GenerateDocumentsJob;
use App\Services\Credits\AppliedCouponsService;
use App\Services\Fees\InitFromAdjustedChargeFeeService;
use App\Jobs\Integrations\Aggregator\Invoices\CreateJob;
use App\Services\Fees\InitFromAdjustedFixedChargeFeeService;
use App\Services\AdjustedFees\CreateService as AdjustedFeesCreateService;
use App\Services\Credits\ProgressiveBillingService as CreditsFromProgressiveBilling;

/**
 * Port of Rails' Invoices::RegenerateFromVoidedService
 * (app/services/invoices/regenerate_from_voided_service.rb) — builds a fresh
 * invoice from a voided one: the voided fees are duplicated (or replaced by
 * adjusted fees), totals / coupons / taxes are recomputed and the invoice is
 * finalized in one transaction.
 *
 * TODO(port) emission points left at their exact Rails positions:
 * activity_loggable (invoice.regenerated), the credit-note credit
 * (Credits::CreditNoteService), the applied prepaid credits
 * (Credits::AppliedPrepaidCreditsService — wallets' prepaid credit flow), the
 * presentation-breakdown copies (PresentationBreakdown unported),
 * SegmentTrack + ActivityLog in call_invoice_finalization_jobs, the Hubspot
 * sync leg and Invoices::Payments::CreateService.call_async.
 */
class RegenerateFromVoidedService extends BaseService
{
    /** Rails' `purchase_order_number = :inherit` sentinel. */
    public const PURCHASE_ORDER_NUMBER_INHERIT = 'inherit';

    private ?Invoice $regeneratedInvoice = null;

    /**
     * @param  list<array<string, mixed>>  $feesParams
     */
    public function __construct(
        private readonly ?Invoice $voidedInvoice,
        private readonly array $feesParams,
        private readonly ?string $purchaseOrderNumber = 'inherit',
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice');

        if ($this->voidedInvoice === null) {
            return $result->notFoundFailure('invoice');
        }

        DB::transaction(function () use ($result): void {
            $this->createRegeneratedInvoice();
            $this->createInvoiceSubscriptions();
            $this->processFees();
            $this->adjustFees();
            $this->assignAppliedUsageThresholds();
            ApplyInvoiceCustomSectionsService::callBang(invoice: $this->regeneratedInvoice);

            $this->regeneratedInvoice->fees_amount_cents = (int) $this->regeneratedInvoice->fees()->sum('amount_cents');
            $this->regeneratedInvoice->sub_total_excluding_taxes_amount_cents = (int) $this->regeneratedInvoice->fees()->sum('amount_cents');

            // apply taxes credits and coupons
            CreditsFromProgressiveBilling::callBang(invoice: $this->regeneratedInvoice);

            if ($this->shouldCreateCouponCredit()) {
                AppliedCouponsService::callBang(invoice: $this->regeneratedInvoice);
                // Rails: regenerated_invoice.fees.reload — drop the cached
                // relation so adjust_fees sees the coupon-adjusted fees.
                $this->regeneratedInvoice->unsetRelation('fees');
            }

            $totalsResult = ComputeTaxesAndTotalsService::call(
                invoice: $this->regeneratedInvoice,
                finalizing: true,
            );

            // We intentionally return early from the transaction block if tax
            // computation fails — this is an async call; we still want to
            // persist the regenerated invoice in its current state. It will be
            // finalized later when we get the taxes back
            // (Invoices::ProviderTaxes::PullTaxesAndApplyService).
            if (! $totalsResult->success() && $this->regeneratedInvoice->tax_status === 'pending') {
                $result->invoice = $this->regeneratedInvoice;

                return;
            }

            // TODO(port): create_credit_note_credit
            // (Credits::CreditNoteService) + create_applied_prepaid_credit
            // (Credits::AppliedPrepaidCreditsService).

            $this->regeneratedInvoice->payment_status = $this->regeneratedInvoice->total_amount_cents > 0
                ? InvoicePaymentStatus::Pending
                : InvoicePaymentStatus::Succeeded;
            $this->regeneratedInvoice->issuing_date = $this->issuingDate();
            $this->regeneratedInvoice->payment_due_date = $this->paymentDueDate();
            TransitionToFinalStatusService::callBang(invoice: $this->regeneratedInvoice);
            $this->regeneratedInvoice->save();
        });

        if ($result->invoice === null) {
            $result->invoice = $this->regeneratedInvoice;
        }

        $this->callInvoiceFinalizationJobs($this->regeneratedInvoice);

        return $result;
    }

    private function issuingDate(): Carbon
    {
        return \Illuminate\Support\Facades\Date::now($this->voidedInvoice->customer->applicableTimezone())->startOfDay();
    }

    private function paymentDueDate(): Carbon
    {
        return $this->issuingDate()
            ->copy()
            ->addDays($this->voidedInvoice->customer->applicableNetPaymentTerm());
    }

    private function shouldCreateCouponCredit(): bool
    {
        return (int) ($this->regeneratedInvoice->fees_amount_cents ?? 0) > 0;
    }

    private function wallets(): \Illuminate\Support\Collection
    {
        return $this->voidedInvoice->customer->wallets()
            ->active()
            ->withPositiveBalance()
            ->get();
    }

    /**
     * Rails: voided_invoice_fees — the voided invoice's fees referenced by
     * fees_params ids, indexed by id.
     *
     * @return array<string, Fee>
     */
    private function voidedInvoiceFees(): array
    {
        $ids = array_values(array_filter(
            array_map(fn (array $fee) => $fee['id'] ?? null, $this->feesParams),
        ));

        if ($ids === []) {
            return [];
        }

        return $this->voidedInvoice->fees()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    private function processFees(): void
    {
        $voidedInvoiceFees = $this->voidedInvoiceFees();

        foreach ($this->feesParams as $feeParams) {
            $dupFee = null;

            if (($feeParams['id'] ?? null) !== null && isset($voidedInvoiceFees[$feeParams['id']])) {
                $dupFee = $this->duplicateFee($voidedInvoiceFees[$feeParams['id']], $feeParams);
            }

            $adjustedFeeParams = [
                'invoice_display_name' => $feeParams['invoice_display_name'] ?? null,
                'units' => $feeParams['units'] ?? null,
                'charge_id' => $feeParams['charge_id'] ?? null,
                'charge_filter_id' => $feeParams['charge_filter_id'] ?? null,
                'subscription_id' => $feeParams['subscription_id'] ?? null,
            ];

            if (($feeParams['unit_amount_cents'] ?? null) !== null) {
                $adjustedFeeParams['unit_precise_amount'] = $feeParams['unit_amount_cents'];
            }

            if ($dupFee !== null) {
                $adjustedFeeParams['fee_id'] = $dupFee->id;
            }

            AdjustedFeesCreateService::callBang(
                invoice: $this->regeneratedInvoice,
                params: $adjustedFeeParams,
                regeneratingVoided: true,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $feeParams
     */
    private function duplicateFee(Fee $voidedFee, array $feeParams): Fee
    {
        $dupFee = $voidedFee->replicate();
        $dupFee->invoice_id = $this->regeneratedInvoice->id;
        $dupFee->payment_status = FeePaymentStatus::Pending;
        $dupFee->taxes_amount_cents = 0;
        $dupFee->taxes_precise_amount_cents = '0';
        $dupFee->precise_coupons_amount_cents = '0';
        $dupFee->taxes_base_rate = 0;
        $dupFee->taxes_rate = 0;
        $dupFee->original_fee_id = $voidedFee->original_fee_id ?? $voidedFee->id;
        $dupFee->save();

        if ($this->adjustingUnits($voidedFee, $feeParams)) {
            return $dupFee;
        }

        // TODO(port): the presentation_breakdowns copies
        // (PresentationBreakdown model unported).

        return $dupFee;
    }

    /**
     * @param  array<string, mixed>  $feeParams
     */
    private function adjustingUnits(Fee $voidedFee, array $feeParams): bool
    {
        if (($feeParams['units'] ?? null) === null) {
            return true;
        }

        return MoneyMath::compare((string) $feeParams['units'], (string) $voidedFee->units) !== 0;
    }

    private function createInvoiceSubscriptions(): void
    {
        $this->voidedInvoice->invoiceSubscriptions->each(function (InvoiceSubscription $subscription): void {
            $subscription->regenerated_invoice_id = $this->regeneratedInvoice->id;
            $subscription->save();

            $dup = $subscription->replicate();
            $dup->invoice_id = $this->regeneratedInvoice->id;
            $dup->regenerated_invoice_id = null;
            $dup->save();
        });
    }

    private function adjustFees(): void
    {
        $subunit = (string) Currency::subunitToUnit((string) $this->regeneratedInvoice->currency);

        $this->regeneratedInvoice->fees->each(function (Fee $fee) use ($subunit): void {
            $adjustedFee = $fee->adjustedFee;

            if ($adjustedFee === null) {
                return;
            }

            if ($fee->fee_type === FeeType::Charge) {
                $properties = $fee->chargeFilter?->properties ?? $fee->charge?->properties ?? [];

                $feeResult = InitFromAdjustedChargeFeeService::callBang(
                    adjustedFee: $adjustedFee,
                    boundaries: BillingPeriodBoundaries::fromProperties($fee->properties ?? []),
                    properties: $properties,
                );

                $updated = $feeResult->fee;
                $fee->invoice_display_name = $updated->invoice_display_name;
                $fee->charge_id = $updated->charge_id;
                $fee->subscription_id = $updated->subscription_id;
                $fee->units = $updated->units;
                $fee->unit_amount_cents = $updated->unit_amount_cents;
                $fee->precise_unit_amount = $updated->precise_unit_amount;
                $fee->amount_cents = $updated->amount_cents;
                $fee->precise_amount_cents = $updated->precise_amount_cents;
                $fee->amount_details = $updated->amount_details;
                $fee->charge_filter_id = $updated->charge_filter_id;
            } elseif ($fee->fee_type === FeeType::FixedCharge) {
                $feeResult = InitFromAdjustedFixedChargeFeeService::callBang(
                    adjustedFee: $adjustedFee,
                    boundaries: BillingPeriodBoundaries::fromProperties($fee->properties ?? []),
                    properties: $fee->fixedCharge->properties ?? [],
                );

                $updated = $feeResult->fee;
                $fee->invoice_display_name = $updated->invoice_display_name;
                $fee->fixed_charge_id = $updated->fixed_charge_id;
                $fee->subscription_id = $updated->subscription_id;
                $fee->units = $updated->units;
                $fee->unit_amount_cents = $updated->unit_amount_cents;
                $fee->precise_unit_amount = $updated->precise_unit_amount;
                $fee->amount_cents = $updated->amount_cents;
                $fee->precise_amount_cents = $updated->precise_amount_cents;
                $fee->amount_details = $updated->amount_details;
            } else {
                if (($adjustedFee->invoice_display_name ?? '') !== '') {
                    $fee->invoice_display_name = $adjustedFee->invoice_display_name;
                }
                if (($adjustedFee->charge_id ?? '') !== '') {
                    $fee->charge_id = $adjustedFee->charge_id;
                }
                if (($adjustedFee->subscription_id ?? '') !== '') {
                    $fee->subscription_id = $adjustedFee->subscription_id;
                }
                if ($adjustedFee->units !== null) {
                    $fee->units = $adjustedFee->units;
                }

                $units = (string) $fee->units;

                if ($adjustedFee->adjusted_units) {
                    $unitCents = (string) $fee->unit_amount_cents;
                    $amountCents = MoneyMath::round(MoneyMath::mul($units, $unitCents));
                    $preciseUnitAmount = MoneyMath::fdiv($unitCents, $subunit);
                } else {
                    $unitCents = (string) $adjustedFee->unit_precise_amount_cents;
                    $amountCents = MoneyMath::round(MoneyMath::mul($units, $unitCents));
                    $preciseUnitAmount = MoneyMath::fdiv($unitCents, $subunit);
                }

                $fee->unit_amount_cents = (int) MoneyMath::round($unitCents);
                $fee->precise_unit_amount = $preciseUnitAmount;
                $fee->amount_cents = (int) $amountCents;
                $fee->precise_amount_cents = MoneyMath::mul($units, $unitCents);
            }

            $fee->save();
        });
    }

    private function assignAppliedUsageThresholds(): void
    {
        if (! $this->voidedInvoice->isProgressiveBilling()) {
            return;
        }

        AppliedUsageThreshold::query()
            ->where('invoice_id', $this->voidedInvoice->id)
            ->each(function (AppliedUsageThreshold $appliedUsageThreshold): void {
                $duplicate = $appliedUsageThreshold->replicate();
                $duplicate->invoice_id = $this->regeneratedInvoice->id;
                $duplicate->save();
            });
    }

    private function createRegeneratedInvoice(): void
    {
        $this->regeneratedInvoice = CreateGeneratingService::callBang(
            customer: $this->voidedInvoice->customer,
            invoiceType: $this->voidedInvoice->invoice_type,
            currency: $this->voidedInvoice->currency,
            datetime: $this->voidedInvoice->created_at,
            billingEntity: $this->voidedInvoice->billingEntity,
        )->invoice;

        $this->regeneratedInvoice->voided_invoice_id = $this->voidedInvoice->id;
        $this->regeneratedInvoice->purchase_order_number = $this->resolvedPurchaseOrderNumber();
        $this->regeneratedInvoice->save();

        $this->regeneratedInvoice->refreshSearchTerms();
    }

    /**
     * Rails: resolved_purchase_order_number + the HasPurchaseOrderNumber
     * concern's `normalizes :purchase_order_number, with: -> { strip.presence }`.
     */
    private function resolvedPurchaseOrderNumber(): ?string
    {
        $value = $this->purchaseOrderNumber === self::PURCHASE_ORDER_NUMBER_INHERIT
            ? $this->voidedInvoice->purchase_order_number
            : $this->purchaseOrderNumber;

        $value = mb_trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function callInvoiceFinalizationJobs(Invoice $invoice): void
    {
        if ($invoice->isClosed()) {
            return;
        }

        // TODO(port): Utils::SegmentTrack.invoice_created + ActivityLog.
        SendWebhookJob::performLater('invoice.created', $invoice);
        dispatch(new GenerateDocumentsJob($invoice, $this->shouldDeliverEmail()));
        CreateJob::dispatchIfShouldSync($invoice);
        // TODO(port): Invoices::Payments::CreateService.call_async
        // (payments are a later slice).
    }

    private function shouldDeliverEmail(): bool
    {
        return \App\Support\License::premium()
            && in_array(
                'invoice.finalized',
                (array) ($this->regeneratedInvoice->billingEntity?->email_settings ?? []),
                true,
            );
    }
}
