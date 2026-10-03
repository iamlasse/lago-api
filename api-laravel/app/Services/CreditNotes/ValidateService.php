<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use App\Enums\InvoiceType;
use App\Support\MoneyMath;
use App\Services\BaseResult;

/**
 * Port of Rails' CreditNotes::ValidateService
 * (app/services/credit_notes/validate_service.rb).
 */
class ValidateService extends BaseValidator
{
    public function __construct(
        BaseResult $result,
        protected CreditNote $item,
    ) {
        parent::__construct($result);
    }

    public function valid(): bool
    {
        $this->validInvoiceStatus();
        $this->validItemsAmount();
        $this->validRefundAmount();
        $this->validCreditAmount();
        $this->validOffsetAmount();
        $this->validRemainingInvoiceAmount();
        $this->validTotalAmountPositive();

        if ($this->errors()) {
            $this->result->validationFailure($this->messages());

            return false;
        }

        return true;
    }

    private function totalAmountCents(): int
    {
        return (int) $this->item->credit_amount_cents
            + (int) $this->item->refund_amount_cents
            + (int) $this->item->offset_amount_cents;
    }

    private function creditableAmountCents(): int
    {
        return $this->invoiceCreditableAmounts()->feeTotalAmountCents()
            - $this->creditedInvoiceAmountCents()
            - $this->offsetAmountCents();
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

    private function preciseTotalItemsAmountCents(): int
    {
        $itemsPrecise = (string) $this->item->items->sum(
            fn ($item): string => (string) $item->precise_amount_cents,
        );

        return MoneyMath::round(MoneyMath::add(
            MoneyMath::sub($itemsPrecise, (string) $this->item->precise_coupons_adjustment_amount_cents),
            (string) $this->item->precise_taxes_amount_cents,
        ));
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\CreditNote> */
    private function otherCreditNotes(): \Illuminate\Database\Eloquent\Builder
    {
        return CreditNote::query()
            ->where('invoice_id', $this->item->invoice_id)
            ->finalized()
            ->where('id', '!=', $this->item->id);
    }

    private function invoiceCreditableAmounts(): InvoiceCreditableAmounts
    {
        return new InvoiceCreditableAmounts($this->item->invoice);
    }

    // -- Checks ----------------------------------------------------------------

    // NOTE: Check if refunded amount is less than or equal to the invoice's paid amount
    private function validInvoiceStatus(): void
    {
        if ((int) $this->item->refund_amount_cents > 0) {
            if ($this->item->invoice->paymentSucceeded()) {
                return;
            }

            if ((int) $this->item->invoice->total_paid_amount_cents === (int) $this->item->invoice->total_amount_cents
                && (int) $this->item->invoice->total_amount_cents > 0) {
                $this->addError('refund_amount_cents', 'cannot_refund_unpaid_invoice');
            }
        }
    }

    /**
     * NOTE: Check if total amount matched the items amount.
     * The comparison takes care of the rounding precision.
     */
    private function validItemsAmount(): void
    {
        if (abs($this->totalAmountCents() - $this->preciseTotalItemsAmountCents()) <= 1) {
            return;
        }

        $this->addError('base', 'does_not_match_item_amounts');
    }

    // NOTE: Check if refunded amount is less than or equal to the invoice's paid amount
    private function validRefundAmount(): void
    {
        if ((int) $this->item->refund_amount_cents === 0) {
            return;
        }

        if ((int) $this->item->invoice->total_paid_amount_cents <= 0) {
            $this->addError('refund_amount_cents', 'cannot_refund_unpaid_invoice');

            return;
        }

        $refundablePaidCents = (int) $this->item->invoice->total_paid_amount_cents - $this->refundedInvoiceAmountCents();

        if ((int) $this->item->refund_amount_cents <= $refundablePaidCents) {
            return;
        }

        $this->addError('refund_amount_cents', 'higher_than_remaining_invoice_amount');
    }

    // NOTE: Check if credited amount is less than or equal to invoice fee amount
    private function validCreditAmount(): void
    {
        if ($this->item->invoice->typeEnum() === InvoiceType::Credit && (int) $this->item->credit_amount_cents > 0) {
            $this->addError('credit_amount_cents', 'cannot_credit_invoice');

            return;
        }

        if ((int) $this->item->credit_amount_cents <= $this->creditableAmountCents()) {
            return;
        }

        if (abs((int) $this->item->credit_amount_cents - $this->creditableAmountCents()) > 1) {
            $this->addError('credit_amount_cents', 'higher_than_remaining_invoice_amount');
        }
    }

    private function validOffsetAmount(): void
    {
        if ((int) $this->item->offset_amount_cents === 0) {
            return;
        }

        if (! $this->validCreditInvoiceApplication()) {
            return;
        }

        $invoiceDueAmountCents = (int) $this->item->invoice->total_amount_cents
            - (int) $this->item->invoice->total_paid_amount_cents
            - $this->offsetAmountCents();

        $offsettableAmount = min($invoiceDueAmountCents, $this->creditableAmountCents());

        if ((int) $this->item->offset_amount_cents <= $offsettableAmount) {
            return;
        }

        $this->addError('offset_amount_cents', 'higher_than_remaining_invoice_amount');
    }

    private function validCreditInvoiceApplication(): bool
    {
        if ($this->item->invoice->typeEnum() !== InvoiceType::Credit) {
            return true;
        }

        if ((int) $this->item->invoice->total_paid_amount_cents > 0) {
            $this->addError('offset_amount_cents', 'cannot_apply_to_paid_invoice');

            return false;
        }

        if ((int) $this->item->offset_amount_cents !== (int) $this->item->invoice->total_amount_cents) {
            $this->addError('offset_amount_cents', 'not_equal_to_total_amount');

            return false;
        }

        return true;
    }

    // NOTE: Check if total amount is less than or equal to invoice fee amount
    private function validRemainingInvoiceAmount(): void
    {
        $remaining = $this->invoiceCreditableAmounts()->feeTotalAmountCents() - $this->invoiceCreditNoteTotalAmountCents();

        if ($this->totalAmountCents() <= $remaining) {
            return;
        }

        if (abs($this->totalAmountCents() - $remaining) > 1) {
            $this->addError('base', 'higher_than_remaining_invoice_amount');
        }
    }

    // NOTE: Check if total amount is greater than 0
    private function validTotalAmountPositive(): void
    {
        if ($this->totalAmountCents() > 0) {
            return;
        }

        $this->addError('base', 'total_amount_must_be_positive');
    }
}
