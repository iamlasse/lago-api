<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Fee;
use App\Models\Event;
use App\Models\Invoice;
use App\Enums\InvoiceType;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Enums\InvoicePaymentStatus;
use App\Enums\SubscriptionInvoicingReason;
use App\Services\Credits\AppliedCouponsService;
use App\Services\Fees\ChargeService\MeteredItem;
use App\Services\Fees\CreatePayInAdvanceService;
use App\Models\Billing\Context as BillingContext;

/**
 * Port of Rails' Invoices::CreatePayInAdvanceChargeService
 * (app/services/invoices/create_pay_in_advance_charge_service.rb) — bills
 * an invoiceable pay-in-advance event into its own invoice.
 *
 * TODO(port): credit note credits + applied prepaid credits
 * (Credits::CreditNoteService / AppliedPrepaidCreditsService),
 * invoice custom sections, SegmentTrack + activity logs,
 * GenerateDocumentsJob, integration syncs and the payment creation
 * (payment providers slice).
 */
class CreatePayInAdvanceChargeService extends BaseService
{
    public function __construct(
        private readonly mixed $timestamp,
        private readonly MeteredItem $meteredItem,
        private readonly BillingContext $billingContext,
        private readonly Event $event,
        private readonly ?string $invoiceId = null,
    ) {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = static::makeResult('invoice', 'invoice_id');

        // Rails: generate_fees runs with estimate: true (fees built, not
        // persisted) so a failing computation leaves no rows behind.
        $feeResult = CreatePayInAdvanceService::call(
            meteredItem: $this->meteredItem,
            billingContext: $this->billingContext,
            event: $this->event,
            estimate: true,
        );

        $feeResult->raiseIfError();

        /** @var list<Fee> $fees */
        $fees = $feeResult->fees ?? [];

        if ($fees === []) {
            return $result;
        }

        $invoice = $this->createGeneratingInvoice();

        foreach ($fees as $fee) {
            $fee->invoice_id = $invoice->id;
            $fee->save();
        }

        $invoice->fees_amount_cents = (int) collect($fees)->sum(fn (Fee $fee) => (int) $fee->amount_cents);
        $invoice->sub_total_excluding_taxes_amount_cents = (int) $invoice->fees_amount_cents;

        if ((int) $invoice->fees_amount_cents > 0) {
            AppliedCouponsService::call(invoice: $invoice)->raiseIfError();
        }

        ApplyInvoiceCustomSectionsService::call(
            invoice: $invoice,
            resources: [$this->billingContext->subscription()],
        );

        ComputeTaxesAndTotalsService::call(invoice: $invoice)->raiseIfError();

        // TODO(port): create_credit_note_credit + create_applied_prepaid_credit.

        $invoice->payment_status = (int) $invoice->total_amount_cents > 0
            ? InvoicePaymentStatus::Pending
            : InvoicePaymentStatus::Succeeded;

        TransitionToFinalStatusService::call(invoice: $invoice)->raiseIfError();

        $invoice->save();

        $result->invoice = $invoice;
        $result->invoice_id = $invoice->id;

        // Rails skips the delivery block when the invoice is still closed
        // (tax-deferred); the local-taxes path always finalizes here.
        if (! $invoice->isClosed()) {
            \App\Jobs\SendWebhookJob::performLater('invoice.created', $invoice);

            // Rails: fee.created webhooks per fee (TODO(port) — the fee.*
            // webhook services are a later slice), SegmentTrack, activity
            // log, documents, integrations, payment creation.
        }

        return $result;
    }

    private function createGeneratingInvoice(): Invoice
    {
        $invoiceResult = CreateGeneratingService::call(
            customer: $this->billingContext->customer(),
            invoiceType: InvoiceType::Subscription,
            currency: $this->meteredItem->currency(),
            datetime: \App\Support\Utils\Datetime::parseIso8601($this->timestamp) ?? now(),
            chargeInAdvance: true,
            invoiceId: $this->invoiceId,
            invoicingReason: SubscriptionInvoicingReason::InAdvanceCharge->value,
            subscriptionGated: true,
        );

        $invoice = $invoiceResult->raiseIfError()->invoice;

        CreateInvoiceSubscriptionService::call(
            invoice: $invoice,
            subscriptions: [$this->billingContext->subscription()],
            timestamp: (\App\Support\Utils\Datetime::parseIso8601($this->timestamp) ?? now())->getTimestamp(),
            invoicingReason: SubscriptionInvoicingReason::InAdvanceCharge->value,
        )->raiseIfError();

        return $invoice;
    }
}
