<?php

declare(strict_types=1);

namespace App\Services\CreditNotes;

use App\Services\BaseResult;

/**
 * Port of Rails' CreditNotes::AdjustAmountsWithRoundingService
 * (app/services/credit_notes/adjust_amounts_with_rounding_service.rb).
 *
 * NOTE: The goal of this service is to adjust the amounts so that
 * sub total excluding taxes + taxes amount = total amount, taking the
 * rounding into account.
 */
class AdjustAmountsWithRoundingService extends \App\Services\BaseService
{
    public function __construct(private readonly \App\Models\CreditNote $creditNote) {}

    public function execute(): BaseResult
    {
        $result = BaseResult::of('credit_note');

        $subtotal = (int) $this->creditNote->total_amount_cents - (int) $this->creditNote->taxes_amount_cents;

        if ($subtotal !== $this->creditNote->subTotalExcludingTaxesAmountCents()) {
            if ($subtotal > $this->creditNote->subTotalExcludingTaxesAmountCents()) {
                $this->creditNote->total_amount_cents -= 1;
            } else {
                $this->creditNote->total_amount_cents += 1;
            }

            if ((int) $this->creditNote->credit_amount_cents > 0) {
                // NOTE: Adjust credit_amount_cents to make sure that we keep
                // total_amount_cents = credit_amount_cents + refund_amount_cents
                $this->creditNote->credit_amount_cents = (int) $this->creditNote->total_amount_cents - (int) $this->creditNote->refund_amount_cents;
            } else {
                $this->creditNote->refund_amount_cents = (int) $this->creditNote->total_amount_cents;
            }

            $this->creditNote->balance_amount_cents = (int) $this->creditNote->credit_amount_cents;
        }

        $result->credit_note = $this->creditNote;

        return $result;
    }
}
