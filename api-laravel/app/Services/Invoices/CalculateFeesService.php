<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use ReflectionMethod;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use App\Services\BaseResult;
use App\Support\Utils\Datetime;
use Illuminate\Support\Facades\DB;
use App\Models\InvoiceSubscription;
use App\Services\Fees\ChargeService;
use App\Models\BillingPeriodBoundaries;
use App\Services\Fees\SubscriptionService;
use App\Services\Fees\ChargeService\Options;
use App\Services\Subscriptions\DatesService;
use App\Services\Credits\AppliedCouponsService;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Services\Credits\ProgressiveBillingService;
use App\Services\Subscriptions\TerminatedDatesService;

/**
 * Port of Rails' Invoices::CalculateFeesService
 * (app/services/invoices/calculate_fees_service.rb) — creates the
 * subscription and charge fees, then applies progressive billing credits,
 * coupons and the invoice-level taxes/totals rollup.
 *
 * M1 seams (TODO(port)):
 * - fixed charges (Fees::FixedChargeService) — not ported.
 * - minimum commitment true-up (Fees::Commitments::Minimum) — not ported.
 * - recurring non-invoiceable fees — partially ported (charge service with
 *   invoice: null); the webhook emission lives in the subscription service.
 * - Events::BillingPeriodFilterService (charge filters per period) — M2.
 * - credit notes / prepaid credits at finalization — not ported.
 */
class CalculateFeesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly bool $recurring = false,
        private readonly ?string $context = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice', 'non_invoiceable_fees');

        DB::transaction(function () use ($result): void {
            foreach ($this->invoice->invoiceSubscriptions as $invoiceSubscription) {
                $subscription = $invoiceSubscription->subscription;

                $dateService = $this->dateService($subscription, $invoiceSubscription);
                $dateService = (new TerminatedDatesService(
                    subscription: $subscription,
                    invoice: $this->invoice,
                    dateService: $dateService,
                ))->call();

                $boundaries = new BillingPeriodBoundaries(
                    fromDatetime: $invoiceSubscription->from_datetime,
                    toDatetime: $invoiceSubscription->to_datetime,
                    chargesFromDatetime: $invoiceSubscription->charges_from_datetime,
                    chargesToDatetime: $invoiceSubscription->charges_to_datetime,
                    fixedChargesFromDatetime: $invoiceSubscription->fixed_charges_from_datetime,
                    fixedChargesToDatetime: $invoiceSubscription->fixed_charges_to_datetime,
                    timestamp: $invoiceSubscription->timestamp,
                    chargesDuration: $dateService->chargesDurationInDays(),
                    fixedChargesDuration: $dateService->fixedChargesDurationInDays(),
                );

                if ($this->shouldCreateSubscriptionFee($subscription, $boundaries)) {
                    SubscriptionService::call(
                        invoice: $this->invoice,
                        subscription: $subscription,
                        boundaries: $boundaries,
                    )->raiseIfError();
                }

                if ($this->shouldCreateChargeFees($subscription)) {
                    $this->createChargesFees($subscription, $boundaries, $result);
                }

                // TODO(port): fixed charge fees (Fees::FixedChargeService).
                // TODO(port): recurring non-invoiceable fees.
                // TODO(port): minimum commitment true-up fee.
            }

            $this->invoice->fees_amount_cents = (int) $this->invoice->fees()->sum('amount_cents');
            $this->invoice->sub_total_excluding_taxes_amount_cents =
                (int) $this->invoice->fees_amount_cents - (int) $this->invoice->coupons_amount_cents;

            ProgressiveBillingService::call(invoice: $this->invoice);

            if ($this->shouldCreateCouponCredit()) {
                AppliedCouponsService::call(invoice: $this->invoice);
            }

            $totalsResult = ComputeTaxesAndTotalsService::call(
                invoice: $this->invoice,
                finalizing: $this->finalizingInvoice(),
            );

            if (! $totalsResult->success()
                && $totalsResult->getError() instanceof \App\Services\Failures\UnknownTaxFailure) {
                return;
            }

            $totalsResult->raiseIfError();

            // TODO(port): credit note credits + applied prepaid credits
            // (Credits::CreditNoteService / AppliedPrepaidCreditsService).

            $this->invoice->payment_status = (int) $this->invoice->total_amount_cents > 0
                ? \App\Enums\InvoicePaymentStatus::Pending
                : \App\Enums\InvoicePaymentStatus::Succeeded;

            $this->invoice->save();

            $result->invoice = $this->invoice->refresh();
            $result->non_invoiceable_fees ??= [];
        });

        return $result;
    }

    // TODO(integration): verify signature against ported DatesService
    private function dateService($subscription, ?InvoiceSubscription $invoiceSubscription = null): DatesService
    {
        $timestamp = $invoiceSubscription?->timestamp ?? $this->invoice->invoiceSubscriptions->first()?->timestamp;

        return DatesService::newInstance(
            $subscription,
            CarbonImmutable::instance(Datetime::parseIso8601($timestamp))->utc(),
            currentUsage: $subscription->terminated() && $subscription->upgraded(),
        );
    }

    private function issuingDate(): CarbonImmutable
    {
        return Datetime::parseIso8601($this->invoice->issuing_date)
            ->setTimezone($this->invoice->customer->applicableTimezone())
            ->startOfDay();
    }

    private function createChargesFees($subscription, BillingPeriodBoundaries $boundaries, BaseResult $result): void
    {
        if (! $this->chargeBoundariesValid($boundaries)) {
            return;
        }

        // NOTE: only invoiceable, non-pay-in-advance (unless the metric is
        // recurring) charges are billed here. Charge filters (per-filter fees
        // + filtered aggregations) arrive with the M2 event store.
        $charges = $subscription->plan->charges()
            ->join('billable_metrics', 'billable_metrics.id', '=', 'charges.billable_metric_id')
            ->where('charges.invoiceable', true)
            ->where(function ($query): void {
                $query->where('charges.pay_in_advance', false)
                    ->orWhere('billable_metrics.recurring', true);
            })
            ->select('charges.*')
            ->get();

        foreach ($charges as $charge) {
            if ($this->shouldNotCreateChargeFee($charge, $subscription)) {
                continue;
            }

            $feeResult = ChargeService::call(
                invoice: $this->invoice,
                meteredItem: MeteredItem::fromCharge($charge, $boundaries),
                subscription: $subscription,
                options: new Options(
                    context: $this->context,
                    skipAdjustedFees: true, // TODO(port): AdjustedFee existence check.
                ),
            );

            try {
                $feeResult->raiseIfError();
            } catch (\App\Services\Failures\FailedResult $e) {
                $result->failWithError($e);

                return;
            }
        }
    }

    private function chargeBoundariesValid(BillingPeriodBoundaries $boundaries): bool
    {
        // TODO: Investigate why invalid boundaries are even possible
        if ($boundaries->chargesFromDatetime === null || $boundaries->chargesToDatetime === null) {
            return false;
        }

        return $boundaries->chargesFromDatetime->lt($boundaries->chargesToDatetime);
    }

    private function shouldNotCreateChargeFee($charge, $subscription): bool
    {
        if ($charge->payInAdvance()) {
            return $charge->billableMetric->recurring
                && $subscription->terminated()
                && ($subscription->upgraded() || $subscription->nextSubscription() === null);
        }

        if ($charge->proratedCharge()) {
            return false;
        }

        return $charge->billableMetric->recurring
            && $subscription->terminated()
            && $subscription->upgraded();
        // TODO(port): charge.included_in_next_subscription?(subscription)
    }

    private function shouldCreateSubscriptionFee($subscription, BillingPeriodBoundaries $boundaries): bool
    {
        // NOTE: When plan is pay in advance we generate an invoice upon
        // subscription creation; prevent a subscription fee if the
        // subscription creation already happened on billing day.
        $issuingDate = $this->issuingDate();
        $feeExists = $subscription->fees()
            ->subscription()
            ->whereHas('invoice')
            ->whereBetween('created_at', [$issuingDate->copy()->startOfDay(), $issuingDate->copy()->endOfDay()])
            ->where('invoice_id', '!=', $this->invoice->id)
            ->when($this->invoice->voided_invoice_id !== null, fn ($q) => $q->where('invoice_id', '!=', $this->invoice->voided_invoice_id))
            ->exists();

        if ($subscription->plan->pay_in_advance && $feeExists) {
            return false;
        }

        if (! $this->shouldCreateYearlySubscriptionFee($subscription)) {
            return false;
        }

        if (! $this->shouldCreateSemiannualSubscriptionFee($subscription)) {
            return false;
        }

        if ($this->inTrialPeriodNotEndingToday($subscription, $boundaries->timestamp)) {
            return false;
        }

        // Now we have a case where we bill a subscription on the first day,
        // but it's not a pay_in_advance plan — it includes pay_in_advance
        // fixed charges.
        if ($this->billingAdvanceFixedChargesOnFirstInvoice($subscription)) {
            return false;
        }

        // NOTE: When a subscription is terminated we still need to charge the
        // subscription fee if the plan is pay in arrears, otherwise this fee
        // would never be created.
        return $subscription->active()
            || $subscription->incomplete()
            || ($subscription->terminated() && $subscription->plan->payInArrears())
            || ($subscription->terminated() && ($subscription->terminated_at?->gt($this->invoice->created_at) ?? false));
    }

    /**
     * Rails calls the DatesService helpers `first_month_in_semiannual_period?`
     * / `first_month_in_yearly_period?`; they are protected on the ported
     * DatesService (owned by the Subscriptions slice), so they are invoked
     * reflectively.
     *
     * TODO(integration): verify signature against ported DatesService —
     * expose these as public helpers instead of reflection.
     */
    private function datesServiceHelper(DatesService $dateService, string $method): bool
    {
        $reflection = new ReflectionMethod($dateService, $method);
        $reflection->setAccessible(true);

        return (bool) $reflection->invoke($dateService);
    }

    private function shouldCreateSemiannualSubscriptionFee($subscription): bool
    {
        $plan = $subscription->plan;

        if (! $plan->semiannual()) {
            return true;
        }

        if ($plan->pay_in_advance && ! $subscription->startedInPast()) {
            return $this->datesServiceHelper($this->dateService($subscription), 'firstMonthInSemiannualPeriod')
                || ! $subscription->alreadyBilled();
        }

        if ($plan->pay_in_advance && $subscription->startedInPast()) {
            // TODO(port): first_month_in_first_semiannual_period — protected on
            // the ported DatesService; approximated by firstMonthInSemiannualPeriod.
            return ! $this->datesServiceHelper($this->dateService($subscription), 'firstMonthInSemiannualPeriod');
        }

        if ($plan->payInArrears()) {
            return $subscription->terminated()
                || $this->datesServiceHelper($this->dateService($subscription), 'firstMonthInSemiannualPeriod');
        }

        return false;
    }

    private function shouldCreateYearlySubscriptionFee($subscription): bool
    {
        $plan = $subscription->plan;

        if (! $plan->yearly()) {
            return true;
        }

        if ($plan->pay_in_advance && ! $subscription->startedInPast()) {
            return $this->datesServiceHelper($this->dateService($subscription), 'firstMonthInYearlyPeriod')
                || ! $subscription->alreadyBilled();
        }

        if ($plan->pay_in_advance && $subscription->startedInPast()) {
            // TODO(port): first_month_in_first_yearly_period (see semiannual note).
            return ! $this->datesServiceHelper($this->dateService($subscription), 'firstMonthInYearlyPeriod');
        }

        if ($plan->payInArrears()) {
            return $subscription->terminated()
                || $this->datesServiceHelper($this->dateService($subscription), 'firstMonthInYearlyPeriod');
        }

        return false;
    }

    private function shouldCreateChargeFees($subscription): bool
    {
        if ($this->invoice->skip_charges) {
            return false;
        }

        // We should look at the charges if the subscription was created in
        // the past and it is not an upgrade.
        if ($subscription->plan->pay_in_advance
            && $subscription->startedInPast()
            && $subscription->previous_subscription_id === null) {
            return true;
        }

        return true;
    }

    private function inTrialPeriodNotEndingToday($subscription, mixed $timestamp): bool
    {
        if (! $subscription->inTrialPeriod()) {
            return false;
        }

        $tz = $subscription->customer->applicableTimezone();

        return Datetime::parseIso8601($timestamp)
            ->setTimezone($tz)->startOfDay()
            ->ne(Datetime::parseIso8601($subscription->trialEndDatetime())->setTimezone($tz)->startOfDay());
    }

    private function billingAdvanceFixedChargesOnFirstInvoice($subscription): bool
    {
        $invoiceSubscriptionsCount = $subscription->invoiceSubscriptions()->count();

        if ($invoiceSubscriptionsCount > 1) {
            return false;
        }

        $last = $subscription->invoiceSubscriptions()->orderByDesc('created_at')->first();

        if ($last === null || ! $last->subscriptionStarting()) {
            return false;
        }

        if (! $subscription->plan->fixedCharges()->where('pay_in_advance', true)->exists()) {
            return false;
        }

        if ($subscription->plan->pay_in_advance) {
            return false;
        }

        // At this point we have an invoice for a starting subscription
        // (billed the first time), the plan is not paid in advance and there
        // are some pay_in_advance fixed charges.
        return true;
    }

    private function shouldCreateCouponCredit(): bool
    {
        if ($this->notInFinalizingProcess()) {
            return false;
        }

        return (int) $this->invoice->fees_amount_cents > 0;
    }

    private function notInFinalizingProcess(): bool
    {
        return ! $this->finalizingInvoice();
    }

    private function finalizingInvoice(): bool
    {
        if ($this->context === 'finalize') {
            return true;
        }

        $statusName = $this->invoice->statusEnum()?->label();

        return $statusName !== null && in_array($statusName, Invoice::GENERATED_STATUS_NAMES, true);
    }
}
