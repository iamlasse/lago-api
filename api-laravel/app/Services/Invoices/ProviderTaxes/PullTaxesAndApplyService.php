<?php

declare(strict_types=1);

namespace App\Services\Invoices\ProviderTaxes;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use App\Enums\InvoiceTaxStatus;
use App\Jobs\Invoices\GenerateDocumentsJob;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use Illuminate\Support\Facades\Date;
use App\Services\Invoices\ComputeAmountsFromFees;
use App\Services\Invoices\TransitionToFinalStatusService;
use App\Services\Integrations\Aggregator\Taxes\Invoices\CreateService;
use App\Services\Integrations\Aggregator\Taxes\Invoices\CreateDraftService;
use App\Services\Invoices\Payments\CreateService as InvoicePaymentsCreateService;

/**
 * Port of Rails' Invoices::ProviderTaxes::PullTaxesAndApplyService
 * (app/services/invoices/provider_taxes/pull_taxes_and_apply_service.rb) —
 * the async leg of provider taxation: pull the provider's taxes for a
 * pending invoice, recompute the amounts from the fee answers, and take the
 * invoice to its final status.
 *
 * TODO(port): ErrorDetails::CreateService (the tax_error detail rows) — the
 * ErrorDetails model is not ported yet, so the failure branch records the
 * failure on the invoice statuses only.
 *
 * TODO(port): Credits::CreditNoteService / Credits::AppliedPrepaidCreditsService
 * (the prepaid-credit consumption of non-draft invoices) and
 * Subscriptions::ActivationRules::Payment::EvaluateService (the zero-amount
 * payment-gated skip) legs.
 */
class PullTaxesAndApplyService extends \App\Services\BaseService
{
    public function __construct(private readonly ?Invoice $invoice)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        $invoice = $this->invoice;

        if ($invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if ($invoice->customer->taxCustomer() === null) {
            return $result->notFoundFailure('integration_customer');
        }

        $statusEnum = $invoice->statusEnum();

        if (! in_array($statusEnum, [InvoiceStatus::Pending, InvoiceStatus::Draft, InvoiceStatus::Open], true)) {
            return $result;
        }

        if (! $invoice->taxPending()) {
            return $result;
        }

        // Rails: invoice.error_details.tax_error.discard_all — TODO(port) with
        // the ErrorDetails model.

        $taxesResult = ($invoice->isDraft() || $invoice->subscriptionGated())
            ? CreateDraftService::call(invoice: $invoice, fees: $invoice->fees->all())
            : CreateService::call(invoice: $invoice, fees: $invoice->fees->all());

        if ($taxesResult->failure()) {
            $error = $taxesResult->getError();

            // TODO(port): create_error_detail — the ErrorDetails model.
            $invoice->tax_status = InvoiceTaxStatus::Failed->value;

            if (! $invoice->isDraft()) {
                $invoice->status = InvoiceStatus::Failed;
            }

            $invoice->save();

            $this->notify_ready_to_finalize($invoice);

            return $result;
        }

        /** @var list<\App\Services\Integrations\Aggregator\Taxes\TaxResult> $providerTaxes */
        $providerTaxes = $taxesResult->fees;

        DB::transaction(function () use ($invoice, $providerTaxes, $result): void {
            $fresh = Invoice::query()->findOrFail($invoice->id);

            if ($fresh->isFinalized() || $fresh->isVoided() || $fresh->isClosed()) {
                return;
            }

            if (! $fresh->isDraft()) {
                $fresh->issuing_date = $this->issuing_date($fresh);
                $fresh->payment_due_date = $this->payment_due_date($fresh);
            }

            ComputeAmountsFromFees::call(invoice: $fresh, provider_taxes: $providerTaxes)->raiseIfError();

            // Rails: create_credit_note_credit (Credits::CreditNoteService) and
            // create_applied_prepaid_credit
            // (Credits::AppliedPrepaidCreditsService) for non-draft,
            // non-one-off invoices — TODO(port) with the credits slice.

            $fresh->payment_status = $fresh->total_amount_cents > 0
                ? InvoicePaymentStatus::Pending->value
                : InvoicePaymentStatus::Succeeded->value;
            $fresh->tax_status = InvoiceTaxStatus::Succeeded->value;

            // Rails: skip_payment_gating_for_zero_amount — TODO(port) with the
            // activation-rules slice.

            if (! $fresh->isDraft()) {
                TransitionToFinalStatusService::call(invoice: $fresh);
            }

            $fresh->save();
            $fresh->refresh();

            $result->invoice = $fresh;
        });

        $invoice = $result->invoice ?? $invoice;

        if ($invoice->subscriptionGated()) {
            (new InvoicePaymentsCreateService(invoice: $invoice))->callAsync();
        } elseif ($invoice->isFinalized()) {
            SendWebhookJob::performLater('invoice.created', $invoice);
            // Rails: Utils::ActivityLog.produce(invoice, "invoice.created") — TODO(port).
            dispatch(new \App\Jobs\Invoices\GenerateDocumentsJob($invoice, $this->should_deliver_email($invoice)));
            // Rails: aggregator invoice sync + hubspot create jobs — TODO(port)
            // with the accounting integrations slice.
            (new InvoicePaymentsCreateService(invoice: $invoice))->callAsync();
            // Rails: Utils::SegmentTrack.invoice_created(invoice) — TODO(port).
        } elseif ($invoice->isDraft()) {
            $this->notify_ready_to_finalize($invoice);
        }

        return $result;
    }

    /** Rails: `notify_ready_to_finalize`. */
    private function notify_ready_to_finalize(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            return;
        }

        SendWebhookJob::performLater('invoice.ready_to_finalize', $invoice);
        // Rails: Utils::ActivityLog.produce(invoice, "invoice.ready_to_finalize") — TODO(port).
    }

    private function should_deliver_email(Invoice $invoice): bool
    {
        return \App\Support\License::premium()
            && in_array('invoice.finalized', (array) ($invoice->billingEntity?->email_settings ?? []), true);
    }

    /**
     * Rails: `issuing_date` — kept on the anchor for recurring
     * subscription invoices with the keep_anchor adjustment, else today in
     * the customer timezone.
     */
    private function issuing_date(Invoice $invoice): string
    {
        if ($this->issuing_date_keep_anchor($invoice)) {
            return $invoice->issuing_date;
        }

        return Date::now($invoice->customer->applicableTimezone())->toDateString();
    }

    private function issuing_date_keep_anchor(Invoice $invoice): bool
    {
        return $invoice->invoiceSubscriptions
            ->contains(fn ($invoiceSubscription) => $invoiceSubscription->recurring)
            && $invoice->customer->applicableSubscriptionInvoiceIssuingDateAdjustment() === 'keep_anchor';
    }

    private function payment_due_date(Invoice $invoice): string
    {
        return Date::parse($this->issuing_date($invoice))
            ->addDays((int) $invoice->customer->applicableNetPaymentTerm())
            ->toDateString();
    }
}
