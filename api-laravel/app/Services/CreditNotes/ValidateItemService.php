<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Enums\InvoiceType;
use App\Enums\InvoicePaymentStatus;
use App\Support\MoneyMath;
use App\Services\BaseResult;

/**
 * Port of Rails' CreditNotes::ValidateItemService
 * (app/services/credit_notes/validate_item_service.rb).
 */
class ValidateItemService extends BaseValidator
{
    public function __construct(
        BaseResult $result,
        protected CreditNoteItem $item,
    ) {
        parent::__construct($result);
    }

    public function valid(): bool
    {
        if (! $this->validFee()) {
            return false;
        }

        $this->validItemAmount();
        $this->validIndividualAmount();

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    private function creditNote(): CreditNote
    {
        return $this->item->creditNote;
    }

    private function invoice()
    {
        return $this->creditNote()->invoice;
    }

    private function creditedFeeAmountCents(): int
    {
        return (int) CreditNoteItem::query()->where('fee_id', $this->item->fee_id)->sum('amount_cents');
    }

    private function refundedInvoiceAmountCents(): int
    {
        return (int) $this->otherCreditNotes()->sum('refund_amount_cents');
    }

    private function creditedInvoiceAmountCents(): int
    {
        return (int) $this->otherCreditNotes()->sum('credit_amount_cents');
    }

    private function offsetAmountCents(): int
    {
        return (int) $this->otherCreditNotes()->sum('offset_amount_cents');
    }

    private function invoiceCreditNoteTotalAmountCents(): int
    {
        return $this->creditedInvoiceAmountCents() + $this->refundedInvoiceAmountCents() + $this->offsetAmountCents();
    }

    private function totalItemAmountCents(): int
    {
        // Ruby: (item.amount_cents + (item.amount_cents * fee.taxes_rate).fdiv(100)).round — float math
        $withTaxes = ((int) $this->item->amount_cents + (((int) $this->item->amount_cents * (float) $this->item->fee->taxes_rate) / 100));

        return (int) round($withTaxes);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\CreditNote> */
    private function otherCreditNotes(): \Illuminate\Database\Eloquent\Builder
    {
        return CreditNote::query()
            ->where('invoice_id', $this->creditNote()->invoice_id)
            ->finalized()
            ->where('id', '!=', $this->creditNote()->id);
    }

    // -- Checks ----------------------------------------------------------------

    private function validFee(): bool
    {
        if ($this->item->fee !== null) {
            return true;
        }

        $this->result->notFoundFailure('fee');

        return false;
    }

    // NOTE: Check if item amount is not negative
    private function validItemAmount(): void
    {
        if ((int) $this->item->amount_cents >= 0) {
            return;
        }

        $this->addError('amount_cents', 'invalid_value');
    }

    // NOTE: Check if item amount is less than or equal to fee remaining creditable amount
    private function validIndividualAmount(): void
    {
        $creditableAmounts = new InvoiceCreditableAmounts($this->invoice());

        if ((int) $this->item->amount_cents <= $creditableAmounts->feeCreditableAmountCents($this->item->fee)) {
            return;
        }

        if ($this->prepaidCreditInvoice()
            && ($this->invoice()->paymentPending() || $this->invoice()->paymentStatusEnum() === InvoicePaymentStatus::Failed)) {
            return;
        }

        if ($this->prepaidCreditInvoice() && $this->walletBalanceInsufficient()) {
            $this->addError('amount_cents', 'higher_than_wallet_balance');

            return;
        }

        $this->addError('amount_cents', 'higher_than_remaining_fee_amount');
    }

    private function prepaidCreditInvoice(): bool
    {
        return $this->invoice()->typeEnum() === InvoiceType::Credit;
    }

    private function walletBalanceInsufficient(): bool
    {
        // Rails: item.amount_cents > invoice.prepaid_credit_fee.creditable_from_wallet_amount_cents —
        // wallets are not ported yet, so the wallet bound is always 0 (TODO(port)).
        return (int) $this->item->amount_cents > 0;
    }

    // NOTE: Check if item amount is less than or equal to invoice remaining creditable amount
    private function validGlobalAmount(): void
    {
        $remaining = (new InvoiceCreditableAmounts($this->invoice()))->feeTotalAmountCents() - $this->invoiceCreditNoteTotalAmountCents();

        if ($this->totalItemAmountCents() <= $remaining) {
            return;
        }

        $this->addError('amount_cents', 'higher_than_remaining_invoice_amount');
    }
}
