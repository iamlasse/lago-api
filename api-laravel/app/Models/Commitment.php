<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Frozen-schema model for `commitments`. Minimal port — only what the Plans
 * services (this slice) need for the premium minimum-commitment write path;
 * the full Commitments domain is a later milestone.
 */
#[Fillable([
    'plan_id',
    'commitment_type',
    'amount_cents',
    'invoice_display_name',
    'organization_id',
])]
#[Table(name: 'commitments')]
class Commitment extends BaseModel
{
    use HasFactory;

    /** Rails: Commitment::COMMITMENT_TYPES (minimum_commitment = 0). */
    public const COMMITMENT_TYPES = ['minimum_commitment'];

    public const MINIMUM_COMMITMENT = 0;

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Rails: `has_many :applied_taxes, class_name: "Commitment::AppliedTax"`. */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(CommitmentTax::class, 'commitment_id');
    }

    /** Rails: `has_many :taxes, through: :applied_taxes`. */
    public function taxes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Tax::class,
            CommitmentTax::class,
            'commitment_id',
            'id',
            'id',
            'tax_id',
        );
    }

    protected function casts(): array
    {
        return [
            'commitment_type' => 'integer',
            'amount_cents' => 'integer',
        ];
    }
}
