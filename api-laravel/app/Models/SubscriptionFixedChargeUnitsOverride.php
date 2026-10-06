<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Port of Rails' Subscription::FixedChargeUnitsOverride
 * (app/models/subscription/fixed_charge_units_override.rb) — the negotiated
 * units of a parent-plan fixed charge, recorded per subscription.
 *
 * PaperTrailTraceable (versions writes through the model) is not ported —
 * the Versionable slice covers `versions` rows for a subset of models.
 */
#[Table('subscription_fixed_charge_units_overrides')]
#[Fillable([
    'organization_id',
    'subscription_id',
    'fixed_charge_id',
    'units',
])]
class SubscriptionFixedChargeUnitsOverride extends \App\Models\BaseModel
{
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function fixedCharge(): BelongsTo
    {
        return $this->belongsTo(FixedCharge::class);
    }
}
