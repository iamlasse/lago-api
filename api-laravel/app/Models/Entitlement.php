<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Entitlement::Entitlement
 * (app/models/entitlement/entitlement.rb) — the grant of a feature to
 * exactly one parent: a plan, a catalog plan (not ported) or a
 * subscription. Stored in `entitlement_entitlements`.
 */
#[Fillable([
    'organization_id',
    'entitlement_feature_id',
    'plan_id',
    'catalog_plan_id',
    'subscription_id',
    'deleted_at',
])]
#[Table(name: 'entitlement_entitlements')]
class Entitlement extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    // -- Relationships --------------------------------------------------------------

    /** Rails: `belongs_to :feature, foreign_key: :entitlement_feature_id`. */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'entitlement_feature_id');
    }

    /** Rails: `belongs_to :plan, optional: true`. */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Rails: `belongs_to :catalog_plan, optional: true` — catalog plans are
     * not part of the legacy-engine port; the frozen schema keeps the
     * catalog_plan_id column (the schema CHECK enforces exactly one parent).
     */

    /** Rails: `belongs_to :subscription, optional: true`. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Rails: `has_many :values, foreign_key: :entitlement_entitlement_id, dependent: :destroy`. */
    public function values(): HasMany
    {
        return $this->hasMany(EntitlementValue::class, 'entitlement_entitlement_id');
    }

    // -- Validations --------------------------------------------------------------------

    /**
     * Port of the Rails model validations — ParentPresenceValidator
     * (exactly one of plan / catalog_plan / subscription, error
     * one_of_plan_or_subscription_required on :base). The schema CHECK
     * (`entitlement_check_exactly_one_parent`) is the database backstop.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        $parents = count(array_filter([
            $this->plan_id,
            $this->catalog_plan_id,
            $this->subscription_id,
        ], fn ($id): bool => $id !== null));

        if ($parents !== 1) {
            $errors['base'] = ['one_of_plan_or_subscription_required'];
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
