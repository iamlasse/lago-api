<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\Invoice;
use App\Enums\InvoiceType;
use App\Models\CreditNote;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\CreditNoteItem;
use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use Illuminate\Support\Facades\DB;
use App\Enums\InvoicePaymentStatus;
use App\Enums\CreditNoteCreditStatus;
use App\Enums\CreditNoteRefundStatus;

/**
 * Port of Rails' CreditNotes::CreateService
 * (app/services/credit_notes/create_service.rb).
 *
 * TODO(port) after_commit tail (finalized credit notes only): the
 * credit_note.created webhook (SendWebhookJob), the activity log, the
 * documents generation job, the email delivery, the payment-provider refund
 * jobs (Stripe/Gocardless/Adyen — Stripe refunds are M4), the tax provider
 * report, the accounting-integration sync, and the Segment track.
 */
class CreateService extends \App\Services\BaseService
{
    public function __construct(
        private readonly ?Invoice $invoice,
        private mixed $items = null,
        private int|string|null $reason = null,
        private readonly ?string $description = null,
        private readonly int $creditAmountCents = 0,
        private readonly int $refundAmountCents = 0,
        private readonly int $offsetAmountCents = 0,
        private readonly mixed $metadata = null,
        private readonly bool $automatic = false,
        private readonly ?string $context = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if (! $this->shouldCreateCreditNote()) {
            return $result->forbiddenFailure();
        }

        if (! $this->validTypeOrStatus()) {
            return $result->notAllowedFailure('invalid_type_or_status');
        }

        $reason = CreditNoteReason::fromOption($this->reason ?? 'other');
        if ($reason === null) {
            return $result->validationFailure(['reason' => ['invalid_value']]);
        }

        $creditNote = new CreditNote([
            'organization_id' => $this->invoice->organization_id,
            'customer_id' => $this->invoice->customer_id,
            'invoice_id' => $this->invoice->id,
            'issuing_date' => $this->issuingDate(),
            'total_amount_currency' => $this->invoice->currency,
            'credit_amount_currency' => $this->invoice->currency,
            'refund_amount_currency' => $this->invoice->currency,
            'offset_amount_currency' => $this->invoice->currency,
            'balance_amount_currency' => $this->invoice->currency,
            'credit_amount_cents' => $this->creditAmountCents,
            'refund_amount_cents' => $this->refundAmountCents,
            'offset_amount_cents' => $this->offsetAmountCents,
            'reason' => $reason,
            'description' => $this->description,
            'credit_status' => CreditNoteCreditStatus::Available,
            'status' => $this->creditNoteStatus(),
        ]);

        $result->credit_note = $creditNote;

        try {
            DB::transaction(function () use ($result, $creditNote): void {
                // TODO(port): metadata (Metadata::ItemMetadata is not ported).

                if ($this->context !== 'preview') {
                    $creditNote->save();
                }

                $this->createItems($result);

                $result->raiseIfError();

                $this->computeAmountsAndTaxes($result)->raiseIfError();

                $this->validCreditNote($result);

                $result->raiseIfError();

                if ($creditNote->credited()) {
                    $creditNote->credit_status = CreditNoteCreditStatus::Available;
                }
                if ($creditNote->refunded()) {
                    $creditNote->refund_status = CreditNoteRefundStatus::Pending;
                }

                $creditNote->total_amount_cents =
                    (int) $creditNote->credit_amount_cents
                    + (int) $creditNote->refund_amount_cents
                    + (int) $creditNote->offset_amount_cents;
                $creditNote->balance_amount_cents = (int) $creditNote->credit_amount_cents;

                AdjustAmountsWithRoundingService::callBang(creditNote: $creditNote);

                if ($this->context === 'preview') {
                    return;
                }

                $creditNote->save();

                // Rails: the applied taxes were pushed onto the association —
                // autosave persists them with the credit note's final save.
                foreach ($creditNote->appliedTaxes as $appliedTax) {
                    if (! $appliedTax->exists) {
                        $appliedTax->credit_note_id = $creditNote->id;
                        $appliedTax->save();
                    }
                }

                if ((int) $creditNote->offset_amount_cents > 0) {
                    // TODO(port): InvoiceSettlements::CreateService — the
                    // invoice_settlements offset row (settlements are not
                    // ported yet).
                }

                // TODO(port): void_prepaid_credit — the wallet transactions
                // void (WalletTransactions::VoidService; wallets are not
                // ported yet).
            });
        } catch (\App\Services\Failures\FailedResult $e) {
            return $result->failWithError($e);
        } catch (\Illuminate\Database\QueryException $e) {
            return $result->validationFailure(['base' => [$e->getMessage()]]);
        }

        if ($this->context === 'preview') {
            return $result;
        }

        // TODO(port): the after_commit tail for finalized credit notes —
        // webhook, activity log, documents job, email, refunds, tax
        // provider report, integrations sync (see class docblock).

        return $result;
    }

    /** NOTE: created from subscription termination, or a premium feature. */
    private function shouldCreateCreditNote(): bool
    {
        if ($this->automatic) {
            return true;
        }

        return $this->premium();
    }

    private function validTypeOrStatus(): bool
    {
        if ($this->automatic) {
            return true;
        }

        if ($this->invoice->typeEnum() === InvoiceType::Credit) {
            // NOTE: the prepaid credit wallet (invoice.associated_active_wallet)
            // is not ported yet — no wallet can exist (TODO(port)).
            if ($this->invoice->paymentPending() || $this->invoice->paymentStatusEnum() === InvoicePaymentStatus::Failed) {
                if ($this->nonOffsetAmountsPresent()) {
                    return false;
                }
            } elseif (! $this->invoice->paymentSucceeded()) {
                return false;
            }
        }

        return (int) $this->invoice->version_number >= InvoiceCreditableAmounts::CREDIT_NOTES_MIN_VERSION;
    }

    private function nonOffsetAmountsPresent(): bool
    {
        return $this->creditAmountCents > 0 || $this->refundAmountCents > 0;
    }

    /**
     * NOTE: credit notes only support draft/finalized; voided invoices map
     * to finalized, and previews are never persisted so finalized is safe.
     */
    private function creditNoteStatus(): int
    {
        if ($this->invoice->isVoided() || $this->context === 'preview') {
            return CreditNoteStatus::Finalized->value;
        }

        return (int) $this->invoice->getRawOriginal('status');
    }

    /** NOTE: issuing_date must be in customer time zone (accounting date). */
    private function issuingDate(): string
    {
        return now($this->invoice->customer->applicableTimezone())->toDateString();
    }

    private function createItems(BaseResult $result): void
    {
        if (! is_array($this->items)) {
            $result->validationFailure(['items' => ['must_be_an_array']])->raiseIfError();
        }

        foreach ($this->items as $itemAttr) {
            $amountCents = (int) ($itemAttr['amount_cents'] ?? 0);

            $fee = $this->invoice->fees()->where('fees.id', $itemAttr['fee_id'] ?? null)->first();

            $item = new CreditNoteItem([
                'organization_id' => $this->invoice->organization_id,
                'fee_id' => $fee?->id,
                'amount_cents' => \App\Support\MoneyMath::round((string) $amountCents),
                'precise_amount_cents' => $amountCents,
                'amount_currency' => $this->invoice->currency,
            ]);
            $item->setRelation('fee', $fee);
            $item->setRelation('creditNote', $result->credit_note);
            $item->credit_note_id = $result->credit_note->id;

            // Keep the item on the in-memory relation — Rails pushes items
            // onto the association, preview included.
            $creditNote = $result->credit_note;
            $creditNote->setRelation('items', $creditNote->items->push($item));

            if (! $this->validItem($item, $result)) {
                break;
            }

            if ($this->context !== 'preview') {
                $item->save();
            }
        }
    }

    private function validItem(CreditNoteItem $item, BaseResult $result): bool
    {
        return (new ValidateItemService($result, item: $item))->valid();
    }

    private function validCreditNote(BaseResult $result): void
    {
        (new ValidateService($result, item: $result->credit_note))->valid();
    }

    private function computeAmountsAndTaxes(BaseResult $result): BaseResult
    {
        $creditNote = $result->credit_note;

        $taxesResult = ComputeTaxesService::call(
            creditNote: $creditNote,
            adjustRounding: $this->creditNoteForAllRemainingAmount(),
        );

        if ($taxesResult->failure()) {
            $result->failWithError($taxesResult->getError());
        }

        return $result;
    }

    private function creditNoteForAllRemainingAmount(): bool
    {
        $creditableAmounts = new InvoiceCreditableAmounts($this->invoice);

        return $creditableAmounts->creditableAmountCents() === 0;
    }
}
