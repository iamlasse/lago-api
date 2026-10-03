<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Jobs\SendWebhookJob;
use App\Services\BaseResult;
use Illuminate\Support\Facades\DB;

/**
 * Port of Rails' Invoices::VoidService
 * (app/services/invoices/void_service.rb) — the finalized → voided AASM
 * transition under a row lock, recrediting wallet/coupon credits.
 *
 * TODO(port) emission points left at their exact Rails positions:
 * activity_loggable (invoice.voided), LifetimeUsages::FlagRefreshFromInvoiceService,
 * AppliedCoupons::RecreditService, WalletTransactions::RecreditService,
 * the credit-note creation branch (CreditNotes::CreateService / EstimateService /
 * VoidService — credit notes are unported; generate_credit_note still passes
 * the premium gate and voids, without creating the credit notes),
 * Invoices::ProviderTaxes::VoidJob and the Hubspot update job.
 */
class VoidService extends \App\Services\BaseService
{
    private bool $generateCreditNote;

    private int $refundAmount;

    private int $creditAmount;

    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly array $params = [],
    ) {
        parent::__construct();

        $this->generateCreditNote = $this->booleanCast($params['generate_credit_note'] ?? null);
        $this->refundAmount = (int) ($params['refund_amount'] ?? 0);
        $this->creditAmount = (int) ($params['credit_amount'] ?? 0);
    }

    public function execute(): BaseResult
    {
        $result = BaseResult::of('invoice');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if (! $this->generateCreditNoteAllowed()) {
            return $result->forbiddenFailure();
        }

        if (! $this->validCreditNoteAmounts()) {
            return $result->singleValidationFailure('total_amount_exceeds_invoice_amount', 'credit_refund_amount');
        }

        $notVoidable = false;

        DB::transaction(function () use (&$notVoidable): void {
            $invoice = Invoice::query()
                ->whereKey($this->invoice->id)
                ->lockForUpdate()
                ->first()
                ?? $this->invoice;

            // Rails: invoice.with_lock { return ... if invoice.voided? } — the
            // AASM void! transition only runs from :finalized; every other
            // state (draft, voided, closed...) raises InvalidTransition,
            // rescued into not_voidable.
            if (! $invoice->isFinalized()) {
                $notVoidable = true;

                return;
            }

            // Rails: event :void, after: :handle_void_transition!.
            $invoice->ready_for_payment_processing = false;
            $invoice->payment_overdue = false;
            $invoice->voided_at = now();
            $invoice->status = InvoiceStatus::Voided;
            $invoice->save();

            // TODO(port): LifetimeUsages::FlagRefreshFromInvoiceService.

            foreach ($invoice->credits as $credit) {
                if ($credit->applied_coupon_id !== null) {
                    // TODO(port): AppliedCoupons::RecreditService.call!(credit:).
                }
            }

            if ($this->generateCreditNote) {
                // When generate_credit_note, we count the wallet value on the creditable value
                // so we don't need to recredit the wallet.
                // TODO(port): create_credit_notes! — CreditNotes::CreateService /
                // EstimateService / VoidService are unported (credit-notes slice).
            }
            // Rails: invoice.wallet_transactions.outbound.each { |wt| recredit
            // if wt.wallet.active? } — TODO(port): wallet transactions and
            // WalletTransactions::RecreditService (wallets milestone).

        });

        if ($notVoidable) {
            return $result->notAllowedFailure('not_voidable');
        }

        $this->invoice->refresh();

        if (! $this->invoice->isVoided()) {
            return $result->serviceFailure('void_operation_failed', 'Failed to void the invoice');
        }

        $result->invoice = $this->invoice;

        SendWebhookJob::performLater('invoice.voided', $result->invoice);
        // TODO(port): Invoices::ProviderTaxes::VoidJob.perform_later(invoice:).
        // TODO(port): Integrations::Aggregator::Invoices::Hubspot::UpdateJob
        // when invoice.should_update_hubspot_invoice?.

        return $result;
    }

    /** Rails: generate_credit_note_allowed? — premium-gated. */
    private function generateCreditNoteAllowed(): bool
    {
        if (! $this->generateCreditNote) {
            return true;
        }

        return $this->premium();
    }

    /** Rails: valid_credit_note_amounts? — guards against over-crediting. */
    private function validCreditNoteAmounts(): bool
    {
        if (! $this->generateCreditNote) {
            return true;
        }

        if ($this->creditAmount > $this->invoice->creditableAmountCents()) {
            return false;
        }

        if ($this->refundAmount > $this->invoice->refundableAmountCents()) {
            return false;
        }

        if (($this->creditAmount + $this->refundAmount) > $this->invoice->creditableAmountCents()) {
            return false;
        }

        return true;
    }

    /** Port of ActiveModel::Type::Boolean#cast (the values that matter here). */
    private function booleanCast(mixed $value): bool
    {
        return in_array($value, ['true', 'TRUE', 't', 'T', '1', 1, true], true);
    }
}
