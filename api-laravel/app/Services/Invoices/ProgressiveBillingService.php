<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Models\LifetimeUsage;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use App\Services\Fees\ChargeService;
use App\Models\AppliedUsageThreshold;
use App\Services\Fees\ChargeService\Options;
use App\Services\Credits\AppliedCouponsService;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Services\Credits\ProgressiveBillingService as CreditsFromProgressiveBilling;

/**
 * Port of Rails' Invoices::ProgressiveBillingService
 * (app/services/invoices/progressive_billing_service.rb) — generates the
 * progressive-billing invoice when a lifetime usage crosses usage thresholds.
 *
 * Not ported (TODO(port)):
 * - Idempotency.unique! (Rails' Sequenced/Idempotency duplicate-invoice
 *   guard — the uniqueness key over organization + subscription + invoiced
 *   usage + threshold amount); RecalculateAndCheckJob relies on it to discard
 *   concurrent runs benignly.
 * - Invoices::ApplyInvoiceCustomSectionsService.
 * - Credits::CreditNoteService / Credits::AppliedPrepaidCreditsService on the
 *   generated invoice (same gap as CalculateFeesService).
 * - Events::BillingPeriodFilterService event filters — the fees run unfiltered
 *   (charge-filter fee splits are the M2 filters pipeline).
 * - Segment tracking, integrations syncs, payments creation, invoice email
 *   documents.
 */
class ProgressiveBillingService extends \App\Services\BaseService
{
    private ?\App\Models\Invoice $invoice = null;

    private ?\App\Models\BillingPeriodBoundaries $boundariesMemo = null;

    public function __construct(
        private readonly array $sortedUsageThresholds,
        private readonly LifetimeUsage $lifetimeUsage,
        private readonly int|\Carbon\CarbonInterface|null $timestamp = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        try {
            $this->invoice = DB::transaction(function (): ?\App\Models\Invoice {
                $this->createGeneratingInvoice();
                $this->createFees();
                $this->createAppliedUsageThresholds();

                $invoice = $this->invoice;

                $invoice->fees_amount_cents = (int) $invoice->fees()->sum('amount_cents');
                $invoice->sub_total_excluding_taxes_amount_cents = (int) $invoice->fees_amount_cents;
                $invoice->save();

                /** @var BaseResult $creditsResult */
                $creditsResult = CreditsFromProgressiveBilling::call(invoice: $invoice);
                $credits = $creditsResult->credits ?? [];

                if ($credits !== [] && $this->sortedUsageThresholds !== [] && end($this->sortedUsageThresholds)->recurring) {
                    // TODO(port): Idempotency.unique!(invoice,
                    //   previous_progressive_billing_invoice_id: credits.first.progressive_billing_invoice_id).
                }

                AppliedCouponsService::call(invoice: $invoice);

                // TODO(port): Invoices::ApplyInvoiceCustomSectionsService.

                $totalsResult = ComputeTaxesAndTotalsService::call(invoice: $invoice);

                if (! $totalsResult->success()
                    && $totalsResult->getError() instanceof \App\Services\Failures\UnknownTaxFailure) {
                    return null;
                }

                $totalsResult->raiseIfError();

                // TODO(port): create_credit_note_credit
                // (Credits::CreditNoteService) + create_applied_prepaid_credit
                // (Credits::AppliedPrepaidCreditsService).

                $invoice = $invoice->refresh();

                $invoice->payment_status = (int) $invoice->total_amount_cents > 0
                    ? InvoicePaymentStatus::Pending
                    : InvoicePaymentStatus::Succeeded;
                $invoice->save();

                FinalizeService::callBang(invoice: $invoice->refresh());

                return $invoice->refresh();
            });
        } catch (\App\Services\Failures\FailedResult $e) {
            return $result->failWithError($e);
        }

        $invoice = $this->invoice;

        if ($invoice !== null && $invoice->isFinalized()) {
            // TODO(port): Utils::SegmentTrack.invoice_created / ActivityLog /
            // GenerateDocumentsJob / integrations syncs / Payments::CreateService.
            SendWebhookJob::performLater('invoice.created', $invoice);
        }

        $result->invoice = $invoice;

        return $result;
    }

    private function createGeneratingInvoice(): void
    {
        $subscription = $this->subscription();
        $timestamp = $this->timestamp ?? now();

        $createResult = CreateGeneratingService::call(
            customer: $subscription->customer,
            invoiceType: \App\Enums\InvoiceType::ProgressiveBilling,
            currency: $subscription->plan->amount_currency,
            datetime: $timestamp,
            billingEntity: $subscription->billingEntity ?? $subscription->customer->billingEntity,
            purchaseOrderNumber: $subscription->purchase_order_number,
            invoicingReason: 'progressive_billing',
        );

        $invoice = $createResult->raiseIfError()->invoice;

        CreateInvoiceSubscriptionService::callBang(
            invoice: $invoice,
            subscriptions: [$subscription],
            timestamp: $timestamp instanceof \Carbon\CarbonInterface ? $timestamp->getTimestamp() : (int) $timestamp,
            invoicingReason: 'progressive_billing',
        );

        $this->invoice = $invoice;
    }

    private function createFees(): void
    {
        $subscription = $this->subscription();

        // TODO(port): Events::BillingPeriodFilterService event filters — the
        // filtered aggregations branch (M2 filters pipeline); fees run unfiltered.
        $charges = $subscription->plan->charges()
            ->with(['taxes', 'billableMetric'])
            ->where('invoiceable', true)
            ->where('pay_in_advance', false)
            ->get();

        foreach ($charges as $charge) {
            $feeResult = ChargeService::call(
                invoice: $this->invoice,
                meteredItem: MeteredItem::fromCharge($charge, $this->boundaries()),
                subscription: $subscription,
                options: new Options(context: 'finalize'),
            );

            $feeResult->raiseIfError();
        }
    }

    private function boundaries(): \App\Models\BillingPeriodBoundaries
    {
        if ($this->boundariesMemo === null) {
            $invoiceSubscription = $this->invoice->invoiceSubscriptions->first();

            $dateService = \App\Services\Subscriptions\DatesService::newInstance(
                $this->subscription(),
                \Carbon\CarbonImmutable::parse($this->timestamp ?? now()),
                currentUsage: true,
            );

            $this->boundariesMemo = new \App\Models\BillingPeriodBoundaries(
                fromDatetime: \Carbon\CarbonImmutable::parse($invoiceSubscription->from_datetime),
                toDatetime: \Carbon\CarbonImmutable::parse($invoiceSubscription->to_datetime),
                chargesFromDatetime: \Carbon\CarbonImmutable::parse($invoiceSubscription->charges_from_datetime),
                chargesToDatetime: \Carbon\CarbonImmutable::parse($invoiceSubscription->charges_to_datetime),
                chargesDuration: $dateService->chargesDurationInDays(),
                timestamp: \Carbon\CarbonImmutable::parse($this->timestamp ?? now()),
            );
        }

        return $this->boundariesMemo;
    }

    private function createAppliedUsageThresholds(): void
    {
        foreach ($this->sortedUsageThresholds as $usageThreshold) {
            AppliedUsageThreshold::query()->create([
                'organization_id' => $this->lifetimeUsage->organization_id,
                'invoice_id' => $this->invoice->id,
                'usage_threshold_id' => $usageThreshold->id,
                'lifetime_usage_amount_cents' => $this->lifetimeUsage->totalAmountCents(),
            ]);
        }
    }

    private function subscription(): \App\Models\Subscription
    {
        return $this->lifetimeUsage->subscription;
    }
}
