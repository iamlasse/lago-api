<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\CreditNote as CreditNoteModel;
use App\Services\CreditNotes\InvoiceCreditableAmounts;

/**
 * Field resolvers for the frozen SDL's `CreditNoteEstimate` type (port of
 * Rails' Types::CreditNotes::Estimate). The estimate is an UNPERSISTED
 * CreditNote built by CreditNotes\EstimateService.
 */
class CreditNoteEstimate
{
    /** Rails: currency — total_amount_currency. */
    public function currency(CreditNoteModel $root): ?string
    {
        $currency = $root->currency();

        return $currency !== '' ? $currency : null;
    }

    /** Rails: max_creditable_amount_cents — method: :credit_amount_cents. */
    public function maxCreditableAmountCents(CreditNoteModel $root): int
    {
        return (int) $root->credit_amount_cents;
    }

    /** Rails: max_refundable_amount_cents — method: :refund_amount_cents. */
    public function maxRefundableAmountCents(CreditNoteModel $root): int
    {
        return (int) $root->refund_amount_cents;
    }

    /** Rails: max_offsettable_amount_cents — due clamped into [0, creditable]. */
    public function maxOffsettableAmountCents(CreditNoteModel $root): int
    {
        $creditable = (int) $root->credit_amount_cents;
        $due = $root->invoice === null ? 0 : (new InvoiceCreditableAmounts($root->invoice))->totalDueAmountCents();

        return max(0, min($due, $creditable));
    }

    /** Rails: precise_coupons_adjustment_amount_cents (Float). */
    public function preciseCouponsAdjustmentAmountCents(CreditNoteModel $root): float
    {
        return (float) $root->precise_coupons_adjustment_amount_cents;
    }

    /** Rails: precise_taxes_amount_cents (Float). */
    public function preciseTaxesAmountCents(CreditNoteModel $root): float
    {
        return (float) $root->precise_taxes_amount_cents;
    }

    /** Rails: sub_total_excluding_taxes_amount_cents. */
    public function subTotalExcludingTaxesAmountCents(CreditNoteModel $root): int
    {
        return $root->subTotalExcludingTaxesAmountCents();
    }

    /** Rails: applied_taxes (the estimate's in-memory unsaved taxes). */
    public function appliedTaxes(CreditNoteModel $root): \Illuminate\Support\Collection
    {
        if ($root->relationLoaded('appliedTaxes')) {
            return $root->appliedTaxes;
        }

        return $root->appliedTaxes()->orderByDesc('tax_rate')->get();
    }
}
