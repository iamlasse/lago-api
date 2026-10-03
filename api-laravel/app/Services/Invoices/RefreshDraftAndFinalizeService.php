<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Services\BaseResult;
use App\Jobs\SendWebhookJob;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Invoices::RefreshDraftAndFinalizeService
 * (app/services/invoices/refresh_draft_and_finalize_service.rb) — the PUT
 * /:id/finalize member action: refresh the draft then transition it to its
 * final status.
 *
 * TODO(port) emission points left at their exact Rails positions:
 * credit-note finalization + webhooks (credit notes unported),
 * GenerateDocumentsJob, Integrations::Aggregator jobs,
 * Invoices::Payments::CreateService, Utils::SegmentTrack /
 * ActivityLog and error-details cleanup.
 */
class RefreshDraftAndFinalizeService extends \App\Services\BaseService
{
    public function __construct(private readonly ?Invoice $invoice)
    {
        parent::__construct();
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if (! $this->invoice->isSubscription()) {
            return $result->forbiddenFailure();
        }

        $forbiddenPendingTaxes = false;

        DB::transaction(function () use ($result, &$forbiddenPendingTaxes): void {
            $invoice = Invoice::query()
                ->whereKey($this->invoice->id)
                ->lockForUpdate()
                ->first()
                ?? $this->invoice;

            $result->invoice = $invoice;

            if (! $invoice->isDraft()) {
                return;
            }

            if ($invoice->taxPending()) {
                $forbiddenPendingTaxes = true;

                return;
            }

            $draftedIssuingDate = $invoice->issuing_date;

            $invoice->issuing_date = $this->issuingDate($invoice);

            $refreshResult = RefreshDraftService::call(invoice: $invoice, context: 'finalize');

            $invoice = $invoice->refresh();

            if ($invoice->taxPending()) {
                // When we need to fetch taxes, the invoice isn't finalized
                // until taxes are pulled. So we can't show the final
                // issuing/payment due dates yet.
                // TODO(port): provider taxes set them in
                // Invoices::ProviderTaxes::PullTaxesAndApplyService.
                $invoice->issuing_date = $draftedIssuingDate;
                $invoice->save();

                $result->invoice = $invoice;

                return;
            }

            $refreshResult->raiseIfError();

            $invoice = $invoice->refresh();

            $invoice->payment_due_date = \Illuminate\Support\Carbon::parse($this->issuingDate($invoice))
                ->addDays((int) $invoice->customer->applicableNetPaymentTerm())
                ->toDateString();

            TransitionToFinalStatusService::call(invoice: $invoice);

            // TODO(port): invoice.credit_notes.each(&:finalized!) — credit
            // notes unported.

            $invoice->save();

            $result->invoice = $invoice->refresh();
        });

        if ($forbiddenPendingTaxes) {
            return $result->forbiddenFailure('cannot_finalize_with_pending_taxes');
        }

        $invoice = $result->invoice;

        if ($invoice !== null && $invoice->isDraft()) {
            // Rails returns the (unchanged) invoice without post-processing.
            return $result;
        }

        if ($invoice !== null && ! $invoice->isClosed()) {
            // TODO(port): clear_invoice_generation_errors.
            SendWebhookJob::performLater('invoice.created', $invoice);
            // TODO(port): Utils::ActivityLog.produce(invoice, "invoice.created"),
            // GenerateDocumentsJob (notify: premium + billing entity email
            // settings), aggregator create jobs,
            // Invoices::Payments::CreateService.call_async and
            // Utils::SegmentTrack.invoice_created.
        }

        // TODO(port): credit_note.created webhooks + documents for each
        // finalized credit note.

        return $result;
    }

    /**
     * Rails: issuing_date — keep the draft anchor for recurring invoices
     * whose issuing-date adjustment is "keep_anchor", else today in the
     * customer's timezone.
     */
    private function issuingDate(Invoice $invoice): string
    {
        $invoiceSubscriptions = $invoice->invoiceSubscriptions()->get();
        $recurring = (bool) ($invoiceSubscriptions->first()?->recurring ?? false);

        if ($recurring
            && $invoice->customer->applicableSubscriptionInvoiceIssuingDateAdjustment() === 'keep_anchor') {
            return (string) $invoice->issuing_date;
        }

        return now()
            ->setTimezone($invoice->customer->applicableTimezone())
            ->toDateString();
    }
}
