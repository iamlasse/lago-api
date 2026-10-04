<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `catalog_plans` — the native (v2) catalog plan.
 * Thin port of the Rails CatalogPlan model (app/models/catalog_plan.rb):
 * only the surface the product-catalog slice reads and writes (the plan's
 * own CRUD services are a later slice).
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'invoice_display_name',
    'description',
    'currency',
])]
#[Table(name: 'catalog_plans')]
class CatalogPlan extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: CODE_FORMAT (CatalogCodeFormat concern). */
    public const CODE_FORMAT = '/\A(?!\.+\z)[a-zA-Z0-9_\-.]+\z/';

    // -- Relationships --------------------------------------------------------

    /** Rails: `has_many :applied_rate_cards, class_name: "PlanRateCard"`. */
    public function appliedRateCards(): HasMany
    {
        return $this->hasMany(PlanRateCard::class);
    }

    /** Rails: `has_many :contracts, foreign_key: :catalog_plan_id`. */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'catalog_plan_id');
    }

    // -- Domain methods ---------------------------------------------------------

    /** Rails: `invoice_name`. */
    public function invoiceName(): string
    {
        return ($this->invoice_display_name ?: $this->name) ?? '';
    }

    /** Rails: `attached_to_contracts?` — keeps any contract, discarded rows included. */
    public function attachedToContracts(): bool
    {
        return $this->contracts()->exists();
    }
}
