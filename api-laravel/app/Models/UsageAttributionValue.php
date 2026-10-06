<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `usage_attribution_values` (Rails'
 * UsageAttributionValue) — a customer's attribution value under a type.
 * Only the discarded-presence check of UsageAttributionType needs it.
 */
#[Fillable([
    'organization_id',
    'usage_attribution_type_id',
    'customer_id',
    'parent_id',
    'value',
    'last_seen_at',
])]
#[Table(name: 'usage_attribution_values')]
class UsageAttributionValue extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function usageAttributionType(): BelongsTo
    {
        return $this->belongsTo(UsageAttributionType::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
