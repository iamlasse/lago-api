<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' CreditNote::AppliedTax (app/models/credit_note/applied_tax.rb)
 * — the frozen `credit_notes_taxes` snapshot rows written when credit note
 * taxes are computed.
 */
#[Fillable([
    'credit_note_id',
    'tax_id',
    'tax_description',
    'tax_code',
    'tax_name',
    'tax_rate',
    'amount_cents',
    'amount_currency',
    'base_amount_cents',
    'organization_id',
])]
#[Table(name: 'credit_notes_taxes')]
class CreditNoteAppliedTax extends BaseModel
{
    use HasFactory;

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'tax_rate' => 'float',
            'amount_cents' => 'integer',
            'base_amount_cents' => 'integer',
        ];
    }
}
