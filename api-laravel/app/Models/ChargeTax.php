<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `charges_taxes` (Rails' Charge::AppliedTax).
 * Created by the Charges::ApplyTaxesService port.
 */
#[Fillable([
    'charge_id',
    'tax_id',
    'organization_id',
])]
#[Table(name: 'charges_taxes')]
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
