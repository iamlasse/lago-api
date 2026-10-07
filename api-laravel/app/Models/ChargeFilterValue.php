<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `charge_filter_values` (Rails' ChargeFilterValue) —
 * the per-key allowed values of a charge filter.
 */
#[Fillable([
    'charge_filter_id',
    'billable_metric_filter_id',
    'values',
    'organization_id',
])]
#[Table(name: 'charge_filter_values')]
class ChargeFilterValue extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /** Rails: ChargeFilterValue::ALL_FILTER_VALUES. */
    public const ALL_FILTER_VALUES = '__ALL_FILTER_VALUES__';

    public function chargeFilter(): BelongsTo
    {
        return $this->belongsTo(ChargeFilter::class);
    }

    public function billableMetricFilter(): BelongsTo
    {
        return $this->belongsTo(BillableMetricFilter::class);
    }

    /** Rails: `delegate :key, to: :billable_metric_filter`. */
    public function key(): ?string
    {
        return $this->billableMetricFilter?->key;
    }

    // -- Validations -----------------------------------------------------------

    /**
     * Port of ChargeFilterValue validations — `field => [api error codes]`.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        $values = $this->values;

        if ($values === null || $values === []) {
            $errors['values'] = ['value_is_mandatory'];

            return $errors;
        }

        $allowed = $this->billableMetricFilter?->values ?? [];

        if (count($values) === 1 && $values[0] === self::ALL_FILTER_VALUES) {
            return $errors;
        }

        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                $errors['values'] = ['value_is_invalid'];

                break;
            }
        }

        return $errors;
    }

    /** Rails: default_scope -> { kept.order(updated_at: :asc) }. */
    protected static function booted(): void
    {
        static::addGlobalScope('keptOrdered', fn (Builder $query) => $query->oldest('updated_at'));
    }

    protected function casts(): array
    {
        return [
            'values' => PostgresArray::class,
        ];
    }
}
