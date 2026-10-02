<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Frozen-schema model for `add_ons`. Minimal port — only what the
 * FixedCharges services (this slice) need; the AddOns CRUD surface is a
 * later milestone.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'organization_id',
    'name',
    'code',
    'description',
    'amount_cents',
    'amount_currency',
    'invoice_display_name',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'add_ons')]
class AddOn extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    public function fixedCharges(): HasMany
    {
        return $this->hasMany(FixedCharge::class);
    }

    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(AddOnTax::class, 'add_on_id');
    }

    /** Rails: `has_many :taxes, through: :applied_taxes`. */
    public function taxes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Tax::class,
            AddOnTax::class,
            'add_on_id',
            'id',
            'id',
            'tax_id',
        );
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
        ];
    }
}
