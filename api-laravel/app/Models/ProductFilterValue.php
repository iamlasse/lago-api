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
 * Frozen-schema model for `product_filter_values`. Port of the Rails
 * ProductFilterValue model (app/models/product_filter_value.rb).
 */
#[Fillable([
    'organization_id',
    'product_filter_id',
    'billable_metric_filter_id',
    'value',
])]
#[Table(name: 'product_filter_values')]
class ProductFilterValue extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :product_filter, -> { with_discarded }`. */
    public function productFilter(): BelongsTo
    {
        return $this->belongsTo(ProductFilter::class)->withTrashed();
    }

    /** Rails: `belongs_to :billable_metric_filter, -> { with_discarded }`. */
    public function billableMetricFilter(): BelongsTo
    {
        return $this->belongsTo(BillableMetricFilter::class)->withTrashed();
    }

    /** Rails: `delegate :key, to: :billable_metric_filter`. */
    public function key(): ?string
    {
        return $this->billableMetricFilter?->key;
    }

    // -- Validations ------------------------------------------------------------

    /**
     * Port of the Rails validations — `field => [api error codes]`.
     * A NULL value selects all configured values for the metric filter
     * (allow_nil presence); an empty string is still invalid. The value must
     * be one of the metric filter's configured values.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        // Rails: validates :value, presence: true, allow_nil: true.
        if ($this->value === '') {
            $errors['value'] = ['value_is_mandatory'];
        }

        $metricFilter = $this->billableMetricFilter;

        if ($this->value !== null && $this->value !== '') {
            $configured = (array) ($metricFilter?->values ?? []);

            if (! in_array($this->value, $configured, true)) {
                $errors['value'] = ['value_is_invalid'];
            }
        }

        // Rails: validate_metric_filter_kept (on create) — a discarded filter
        // or a discarded metric must not gain new references.
        if (! $this->exists && $metricFilter !== null) {
            if ($metricFilter->trashed() || $metricFilter->billableMetric?->trashed()) {
                $errors['billable_metric_filter'] = ['billable_metric_deleted'];
            }
        }

        $uniqueness = static::query()
            ->where('product_filter_id', $this->product_filter_id)
            ->where('billable_metric_filter_id', $this->billable_metric_filter_id)
            ->where('value', $this->value)
            ->whereNull('deleted_at');

        if ($this->exists) {
            $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($uniqueness->exists()) {
            $errors['value'] = ['value_already_exist'];
        }

        return $errors;
    }
}
