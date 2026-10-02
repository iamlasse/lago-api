<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Frozen-schema model for `charges_taxes` (Rails' Charge::AppliedTax).
 * Created by the Charges::ApplyTaxesService port.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'charge_id',
    'tax_id',
    'organization_id',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'charges_taxes')]
class ChargeTax extends BaseModel
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
