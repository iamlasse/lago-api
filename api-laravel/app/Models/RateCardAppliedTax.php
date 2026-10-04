<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `rate_cards_taxes`. Port of the Rails
 * RateCard::AppliedTax model (app/models/rate_card/applied_tax.rb,
 * self.table_name = "rate_cards_taxes").
 */
#[Fillable([
    'organization_id',
    'rate_card_id',
    'tax_id',
])]
#[Table(name: 'rate_cards_taxes')]
class RateCardAppliedTax extends BaseModel
{
    use HasFactory;

    public $timestamps = true;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :rate_card`. */
    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }

    /** Rails: `belongs_to :tax`. */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
