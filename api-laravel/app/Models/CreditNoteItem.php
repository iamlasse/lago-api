<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MoneyMath;
use App\Models\Casts\BcNumeric;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' CreditNoteItem (app/models/credit_note_item.rb).
 */
#[Fillable([
    'credit_note_id',
    'fee_id',
    'amount_cents',
    'amount_currency',
    'precise_amount_cents',
    'organization_id',
])]
#[Table(name: 'credit_note_items')]
class CreditNoteItem extends BaseModel
{
    use HasFactory;

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Port of `applied_taxes` — the credit note taxes this item's fee taxes
     * resolve to, mirroring CreditNotes\ApplyTaxesService::findInvoiceAppliedTax:
     * the tax with the same code and rate, or, when the rates differ, the only
     * credit note tax carrying that code. Must stay a relation: TaxHelper
     * orders and plucks on it.
     */
    public function appliedTaxes()
    {
        $feeId = $this->fee_id;

        return $this->creditNote
            ->appliedTaxes()
            ->where(function ($query) use ($feeId): void {
                $query->where(function ($query) use ($feeId): void {
                    $query->whereIn('credit_notes_taxes.tax_code', function ($query) use ($feeId): void {
                        $query->select('tax_code')->from('fees_taxes')->where('fees_taxes.fee_id', $feeId);
                    })->whereIn('credit_notes_taxes.tax_rate', function ($query) use ($feeId): void {
                        $query->select('tax_rate')->from('fees_taxes')->where('fees_taxes.fee_id', $feeId);
                    });
                })->orWhere(function ($query) use ($feeId): void {
                    $query->whereIn('credit_notes_taxes.tax_code', function ($query) use ($feeId): void {
                        $query->select('tax_code')->from('fees_taxes')->where('fees_taxes.fee_id', $feeId);
                    })->whereNotExists(function ($query): void {
                        $query->selectRaw(1)
                            ->from('credit_notes_taxes as same_code')
                            ->whereColumn('same_code.credit_note_id', 'credit_notes_taxes.credit_note_id')
                            ->whereColumn('same_code.tax_code', 'credit_notes_taxes.tax_code')
                            ->whereColumn('same_code.id', '<>', 'credit_notes_taxes.id');
                    });
                });
            });
    }

    /**
     * Port of `sub_total_excluding_taxes_amount_cents` — the item amount
     * with coupons applied proportionally to the way they're applied on the
     * corresponding fee.
     */
    public function subTotalExcludingTaxesAmountCents(): string
    {
        if ((int) $this->amount_cents === 0 || (int) $this->fee->amount_cents === 0) {
            return '0';
        }

        $itemProportionToFee = MoneyMath::fdiv((string) $this->amount_cents, (string) $this->fee->amount_cents);

        return MoneyMath::mul($itemProportionToFee, $this->fee->subTotalExcludingTaxesAmountCents());
    }

    /** Port of `fee_rate` — precise_amount_cents / fee.precise_amount_cents (1 when the fee is zero). */
    public function feeRate(): string
    {
        $feePreciseAmount = (string) $this->fee->precise_amount_cents;

        if ($feePreciseAmount === '0' || $feePreciseAmount === '0.00000') {
            $feePreciseAmount = '1';
        }

        return MoneyMath::fdiv((string) $this->precise_amount_cents, $feePreciseAmount);
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'precise_amount_cents' => [BcNumeric::class, 'scale' => 5],
        ];
    }
}
