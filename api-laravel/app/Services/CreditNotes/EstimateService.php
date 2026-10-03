<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Models\Invoice;
use App\Enums\InvoiceType;
use App\Models\CreditNote;
use App\Support\MoneyMath;
use App\Services\BaseResult;
use App\Services\BaseService;
use App\Models\CreditNoteItem;

/**
 * Port of Rails' CreditNotes::EstimateService
 * (app/services/credit_notes/estimate_service.rb) — "Fetch amounts for
 * credit note creation": builds an unpersisted credit note with the taxes
 * and the maximum creditable/refundable amounts.
 */
class EstimateService extends \App\Services\BaseService
{
    /** @param  list<array<string, mixed>>|mixed  $items */
    public function __construct(
        private readonly ?Invoice $invoice,
        private readonly mixed $items = null,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note');

        if ($this->invoice === null) {
            return $result->notFoundFailure('invoice');
        }

        if (! $this->premium()) {
            return $result->forbiddenFailure();
        }

        if (! $this->validTypeOrStatus()) {
            return $result->notAllowedFailure('invalid_type_or_status');
        }

        $creditNote = new CreditNote([
            'organization_id' => $this->invoice->organization_id,
            'customer_id' => $this->invoice->customer_id,
            'invoice_id' => $this->invoice->id,
            'total_amount_currency' => $this->invoice->currency,
            'credit_amount_currency' => $this->invoice->currency,
            'refund_amount_currency' => $this->invoice->currency,
            'balance_amount_currency' => $this->invoice->currency,
        ]);

        $creditNote->setRelation('items', collect());

        $this->validateItems($result, $creditNote);
        if ($result->failure()) {
            return $result;
        }

        $this->computeAmountsAndTaxes($result, $creditNote);
        if ($result->failure()) {
            return $result;
        }

        $this->adjustAmountsWithRounding($creditNote);

        $result->credit_note = $creditNote;

        return $result;
    }

    private function validTypeOrStatus(): bool
    {
        // NOTE: the prepaid credit wallet (invoice.associated_active_wallet)
        // is not ported yet — no wallet can exist, so credit invoices never
        // estimate (TODO(port)).
        if ($this->invoice->typeEnum() === InvoiceType::Credit) {
            return false;
        }

        return (int) $this->invoice->version_number >= InvoiceCreditableAmounts::CREDIT_NOTES_MIN_VERSION;
    }

    private function validateItems(BaseResult $result, CreditNote $creditNote): void
    {
        if (! is_array($this->items)) {
            $result->validationFailure(['items' => ['must_be_an_array']]);

            return;
        }

        foreach ($this->items as $itemAttr) {
            $amountCents = isset($itemAttr['amount_cents']) ? (int) $itemAttr['amount_cents'] : 0;

            $fee = $this->invoice->fees()->where('fees.id', $itemAttr['fee_id'] ?? null)->first();

            $item = new CreditNoteItem([
                'organization_id' => $this->invoice->organization_id,
                'fee_id' => $fee?->id,
                'amount_cents' => $amountCents,
                'precise_amount_cents' => $amountCents,
                'amount_currency' => $this->invoice->currency,
            ]);
            $item->setRelation('fee', $fee);
            $item->setRelation('creditNote', $creditNote);

            $creditNote->setRelation('items', $creditNote->items->push($item));

            if (! (new ValidateItemService($result, item: $item))->valid()) {
                break;
            }
        }
    }

    private function computeAmountsAndTaxes(BaseResult $result, CreditNote $creditNote): void
    {
        $taxesResult = ComputeTaxesService::call(
            creditNote: $creditNote,
            adjustRounding: $this->creditNoteForAllRemainingAmount($creditNote),
        );

        if ($taxesResult->failure()) {
            $result->failWithError($taxesResult->getError());

            return;
        }

        $creditNote->credit_amount_cents = $this->computeCreditableAmount($creditNote, $taxesResult);

        $this->computeRefundableAmount($creditNote);

        if ($this->invoice->typeEnum() === InvoiceType::Credit) {
            $creditNote->credit_amount_cents = 0;
        }
        $creditNote->total_amount_cents = (int) $creditNote->credit_amount_cents;
    }

    private function creditNoteForAllRemainingAmount(CreditNote $creditNote): bool
    {
        $itemsPrecise = '0';
        foreach ($creditNote->items as $item) {
            $itemsPrecise = MoneyMath::add($itemsPrecise, (string) $item->precise_amount_cents);
        }

        $creditableAmounts = new InvoiceCreditableAmounts($this->invoice);

        $feesCreditable = '0';
        foreach ($this->invoice->fees as $fee) {
            $feesCreditable = MoneyMath::add($feesCreditable, (string) $creditableAmounts->feeCreditableAmountCents($fee));
        }

        return MoneyMath::compare($itemsPrecise, $feesCreditable) === 0;
    }

    private function computeCreditableAmount(CreditNote $creditNote, BaseResult $taxesResult): int
    {
        $itemsAmount = 0;
        foreach ($creditNote->items as $item) {
            $itemsAmount += (int) $item->amount_cents;
        }

        return MoneyMath::round(MoneyMath::add(
            MoneyMath::sub((string) $itemsAmount, (string) $taxesResult->coupons_adjustment_amount_cents),
            (string) $creditNote->precise_taxes_amount_cents,
        ));
    }

    private function computeRefundableAmount(CreditNote $creditNote): void
    {
        $creditNote->refund_amount_cents = (int) $creditNote->credit_amount_cents;

        $refundableAmountCents = (new InvoiceCreditableAmounts($this->invoice))->refundableAmountCents();

        if ((int) $creditNote->credit_amount_cents > $refundableAmountCents) {
            $creditNote->refund_amount_cents = $refundableAmountCents;
        }
    }

    /**
     * NOTE: The goal of this method is to adjust the amounts so that sub
     * total excluding taxes + taxes amount = total amount, taking the
     * rounding into account.
     */
    private function adjustAmountsWithRounding(CreditNote $creditNote): void
    {
        $subtotal = (int) $creditNote->total_amount_cents - (int) $creditNote->taxes_amount_cents;

        if ($subtotal !== $creditNote->subTotalExcludingTaxesAmountCents()) {
            if ($subtotal > $creditNote->subTotalExcludingTaxesAmountCents()) {
                $creditNote->total_amount_cents -= 1;
            } elseif ((int) $creditNote->taxes_amount_cents > 0) {
                $creditNote->taxes_amount_cents -= 1;
            }

            $creditNote->credit_amount_cents = (int) $creditNote->total_amount_cents;
            $creditNote->balance_amount_cents = (int) $creditNote->credit_amount_cents;
        }
    }
}
