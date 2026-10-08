<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `daily_usages`.
 *
 * Port of Rails' DailyUsage (app/models/daily_usage.rb) — the per-day usage
 * snapshot powering revenue analytics (written by the DailyUsages::* slice,
 * read by the exports views and the data pipeline).
 */
#[Fillable([
    'organization_id',
    'customer_id',
    'subscription_id',
    'external_subscription_id',
    'usage',
    'usage_diff',
    'from_datetime',
    'to_datetime',
    'refreshed_at',
    'usage_date',
])]
#[Table(name: 'daily_usages')]
class DailyUsage extends BaseModel
{
    use HasFactory;

    /** Rails: DEFAULT_HISTORY_DAYS = 120. */
    public const int DEFAULT_HISTORY_DAYS = 120;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Rails: `scope :usage_date_in_timezone` — rows whose usage_date is the
     * same calendar day as `timestamp`, where both days are taken in the
     * customer's timezone (customer, then billing entity, then UTC).
     */
    protected function scopeUsageDateInTimezone(Builder $query, $timestamp): Builder
    {
        $atTimeZone = '::timestamptz AT TIME ZONE COALESCE(cus.timezone, billing_entities.timezone, \'UTC\')';

        return $query
            ->select('daily_usages.*')
            ->join('customers as cus', 'daily_usages.customer_id', '=', 'cus.id')
            ->join('billing_entities', 'cus.billing_entity_id', '=', 'billing_entities.id')
            ->whereRaw(
                "DATE((daily_usages.usage_date){$atTimeZone}) = DATE(?{$atTimeZone})",
                [$timestamp]
            );
    }

    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'usage_diff' => 'array',
            'usage_date' => 'date:Y-m-d',
            'from_datetime' => 'datetime',
            'to_datetime' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }
}
