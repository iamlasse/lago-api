<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Entitlement::SubscriptionFeatureRemoval
 * (app/models/entitlement/subscription_feature_removal.rb) — the tombstone
 * marking a feature (or a single privilege) inherited from the plan as
 * removed on one subscription. Stored in
 * `entitlement_subscription_feature_removals`; the schema CHECK
 * (check_exactly_one_feature_or_privilege_removal) enforces that exactly
 * one of feature / privilege is set.
 */
#[Fillable([
    'organization_id',
    'subscription_id',
    'entitlement_feature_id',
    'entitlement_privilege_id',
    'deleted_at',
])]
#[Table(name: 'entitlement_subscription_feature_removals')]
class SubscriptionFeatureRemoval extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    // -- Relationships ----------------------------------------------------------

    /** Rails: `belongs_to :subscription`. */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Rails: `belongs_to :feature, optional: true, foreign_key: :entitlement_feature_id`. */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'entitlement_feature_id');
    }

    /** Rails: `belongs_to :privilege, optional: true, foreign_key: :entitlement_privilege_id`. */
    public function privilege(): BelongsTo
    {
        return $this->belongsTo(Privilege::class, 'entitlement_privilege_id');
    }

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
