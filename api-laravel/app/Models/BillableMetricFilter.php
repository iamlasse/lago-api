<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `billable_metric_filters` (Rails'
 * BillableMetricFilter). The filter keys/values of a billable metric that
 * charge filters narrow down.
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'billable_metric_id',
    'key',
    'values',
    'organization_id',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'billable_metric_filters')]
class BillableMetricFilter extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    public function billableMetric(): BelongsTo
    {
        return $this->belongsTo(BillableMetric::class);
    }

    public function filterValues(): HasMany
    {
        return $this->hasMany(ChargeFilterValue::class, 'billable_metric_filter_id');
    }

    /** Rails: default_scope -> { kept } plus `-> { order(:key) }` on the metric side. */
    protected static function booted(): void
    {
        static::addGlobalScope('ordered', fn (Builder $query) => $query->orderBy('key'));
    }

    protected function casts(): array
    {
        return [
            'values' => PostgresArray::class,
        ];
    }
}
