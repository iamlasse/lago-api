<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `fixed_charges_taxes` (Rails' FixedCharge::AppliedTax).
 * Created by the FixedCharges::ApplyTaxesService port.
 */
#[Fillable([
    'fixed_charge_id',
    'tax_id',
    'organization_id',
])]
#[Table(name: 'fixed_charges_taxes')]
class FixedChargeTax extends BaseModel
{
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    protected function casts(): array
    {
        return [

        ];
    }
}
