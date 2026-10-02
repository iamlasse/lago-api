<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frozen-schema model for `add_ons_taxes` (Rails' AddOn::AppliedTax).
 * Minimal port — referenced by the AddOn model only.
 */
#[Fillable([
    'add_on_id',
    'tax_id',
    'organization_id',
])]
#[Table(name: 'add_ons_taxes')]
class AddOnTax extends BaseModel
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
