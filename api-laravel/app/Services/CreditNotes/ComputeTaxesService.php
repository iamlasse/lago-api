<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Support\MoneyMath;
use App\Support\Allocation;
use App\Services\BaseResult;

/**
 * Port of Rails' CreditNotes::ComputeTaxesService
 * (app/services/credit_notes/compute_taxes_service.rb) — runs the tax
 * rollup and writes the coupon adjustment, taxes and rate onto the credit
 * note, reallocating the rounded total over the applied taxes.
 */
class ComputeTaxesService extends \App\Services\BaseService
{
    public function __construct(
        private readonly \App\Models\CreditNote $creditNote,
        private readonly bool $adjustRounding = false,
    ) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note', 'coupons_adjustment_amount_cents');

        $taxesResult = ApplyTaxesService::call(
            invoice: $this->creditNote->invoice,
            items: $this->creditNote->items,
        );

        if ($taxesResult->failure()) {
            return $result->failWithError($taxesResult->getError());
        }

        $this->creditNote->precise_coupons_adjustment_amount_cents = $taxesResult->coupons_adjustment_amount_cents;
        $this->creditNote->coupons_adjustment_amount_cents = MoneyMath::round((string) $taxesResult->coupons_adjustment_amount_cents);
        $this->creditNote->precise_taxes_amount_cents = $taxesResult->precise_taxes_amount_cents;

        if ($this->adjustRounding) {
            $roundingAdjustments = '0';
            $creditNotes = \App\Models\CreditNote::query()
                ->where('invoice_id', $this->creditNote->invoice_id)
                ->get();
            foreach ($creditNotes as $previousCreditNote) {
                $roundingAdjustments = MoneyMath::add($roundingAdjustments, (string) $previousCreditNote->taxesRoundingAdjustment());
            }
            $this->creditNote->precise_taxes_amount_cents = MoneyMath::sub(
                (string) $this->creditNote->precise_taxes_amount_cents,
                $roundingAdjustments,
            );
        }

        $this->creditNote->taxes_amount_cents = MoneyMath::round((string) $this->creditNote->precise_taxes_amount_cents);
        $this->creditNote->taxes_rate = (float) $taxesResult->taxes_rate;

        $amounts = Allocation::call(
            (int) $this->creditNote->taxes_amount_cents,
            array_map(fn (string $amount): string => $amount, $taxesResult->precise_tax_amounts),
        );

        /**
         * Rails: `credit_note.applied_taxes << applied_tax` — pushed onto
         * the (possibly unsaved) credit note; the caller persists them after
         * saving the credit note.
         *
         * @var \App\Models\CreditNoteAppliedTax $appliedTax
         */
        foreach ($taxesResult->applied_taxes as $index => $appliedTax) {
            $appliedTax->amount_cents = $amounts[$index];
            $this->creditNote->setRelation(
                'appliedTaxes',
                $this->creditNote->appliedTaxes->push($appliedTax),
            );
        }

        $result->credit_note = $this->creditNote;
        $result->coupons_adjustment_amount_cents = $taxesResult->coupons_adjustment_amount_cents;

        return $result;
    }
}
