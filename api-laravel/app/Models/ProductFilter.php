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
 * Frozen-schema model for `product_filters`. Port of the Rails
 * ProductFilter model (app/models/product_filter.rb).
 */
#[Fillable([
    'organization_id',
    'product_id',
    'name',
    'code',
    'description',
    'invoice_display_name',
])]
#[Table(name: 'product_filters')]
class ProductFilter extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: CODE_FORMAT (CatalogCodeFormat concern). */
    public const CODE_FORMAT = '/\A(?!\.+\z)[a-zA-Z0-9_\-.]+\z/';

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :product`. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Rails: `has_many :values, class_name: "ProductFilterValue"`. */
    public function values(): HasMany
    {
        return $this->hasMany(ProductFilterValue::class);
    }

    /** Rails: `has_many :billable_metric_filters, through: :values`. */
    public function billableMetricFilters()
    {
        return $this->belongsToMany(
            BillableMetricFilter::class,
            'product_filter_values',
            'product_filter_id',
            'billable_metric_filter_id',
        )->whereNull('product_filter_values.deleted_at');
    }

    /** Rails: `has_many :rate_cards`. */
    public function rateCards(): HasMany
    {
        return $this->hasMany(RateCard::class);
    }

    // -- Domain methods ---------------------------------------------------------

    /** Rails: `invoice_name`. */
    public function invoiceName(): string
    {
        return ($this->invoice_display_name ?: $this->name) ?? '';
    }

    /** Rails: `delegate :attached_to_plan_or_subscription?, to: :product`. */
    public function attachedToPlanOrSubscription(): bool
    {
        return $this->product->attachedToPlanOrSubscription();
    }

    /** Rails: `attached_to_subscriptions?` — a contract card scoped to this filter. */
    public function attachedToSubscriptions(): bool
    {
        return ContractRateCard::query()
            ->join('rate_cards', 'rate_cards.id', '=', 'contract_rate_cards.rate_card_id')
            ->whereNull('contract_rate_cards.deleted_at')
            ->where('rate_cards.product_filter_id', $this->id)
            ->exists();
    }

    /**
     * Rails: `to_h` — {metric_filter key => [selected values]}.
     *
     * @return array<string, list<string>>
     */
    public function toHash(): array
    {
        $result = [];

        foreach ($this->values as $filterValue) {
            $key = $filterValue->billableMetricFilter->key;
            $result[$key][] = $filterValue->value;
        }

        return $result;
    }

    /**
     * Rails: `to_h_with_all_values` — a null value selects every configured
     * value of the metric filter.
     *
     * @return array<string, list<string>>
     */
    public function toHashWithAllValues(): array
    {
        $result = [];

        foreach ($this->values as $filterValue) {
            $metricFilter = $filterValue->billableMetricFilter;
            $values = $filterValue->value === null
                ? (array) ($metricFilter->values ?? [])
                : [$filterValue->value];

            foreach ($values as $value) {
                $result[$metricFilter->key][] = $value;
            }
        }

        return $result;
    }

    // -- Validations ------------------------------------------------------------

    /**
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $this->validateCode($errors);

        return $errors;
    }

    /** @param array<string, list<string>> $errors */
    protected function validateCode(array &$errors): void
    {
        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];

            return;
        }

        if (preg_match(self::CODE_FORMAT, (string) $this->code) !== 1) {
            $errors['code'] = ['value_is_invalid'];

            return;
        }

        // Rails: uniqueness scope: :product_id, conditions: deleted_at IS NULL.
        $uniqueness = static::query()
            ->where('code', $this->code)
            ->where('product_id', $this->product_id)
            ->whereNull('deleted_at');

        if ($this->exists) {
            $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($uniqueness->exists()) {
            $errors['code'] = ['value_already_exist'];
        }
    }
}
