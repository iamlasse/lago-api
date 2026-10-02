<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `commitments_taxes` (Rails' Commitment::AppliedTax).
 * Minimal port for the Commitments::ApplyTaxesService port.
 */
#[Fillable([
    'commitment_id',
    'tax_id',
    'organization_id',
])]
#[Table(name: 'commitments_taxes')]
class CommitmentTax extends BaseModel
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
