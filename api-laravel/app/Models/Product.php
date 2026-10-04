<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Frozen-schema model for `products`. Port of the Rails Product model
 * (app/models/product.rb): Discard on deleted_at, product_type PG enum,
 * the catalog code format, and the billable-metric / add-on-charge
 * exclusivity validations.
 */
#[Fillable([
    'organization_id',
    'product_category_id',
    'billable_metric_id',
    'add_on_id',
    'charge_id',
    'product_type',
    'code',
    'name',
    'invoice_display_name',
    'description',
])]
#[Table(name: 'products')]
class Product extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: CODE_FORMAT (CatalogCodeFormat concern) — slug-safe codes. */
    public const CODE_FORMAT = '/\A(?!\.+\z)[a-zA-Z0-9_\-.]+\z/';

    /** Rails: PRODUCT_TYPES. */
    public const PRODUCT_TYPES = ['metered' => 'metered', 'fixed' => 'fixed'];

    protected $attributes = [
        // Rails enum default — the column is NOT NULL without a DB default,
        // so every create carries an explicit product_type.
    ];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :product_category, optional: true`. */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /** Rails: `belongs_to :billable_metric, -> { with_discarded }, optional: true`. */
    public function billableMetric(): BelongsTo
    {
        return $this->belongsTo(BillableMetric::class)->withTrashed();
    }

    /** Rails: `belongs_to :add_on, -> { with_discarded }, optional: true`. */
    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class)->withTrashed();
    }

    /** Rails: `belongs_to :charge, -> { with_discarded }, optional: true`. */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class)->withTrashed();
    }

    /** Rails: `has_many :filters, class_name: "ProductFilter"`. */
    public function filters(): HasMany
    {
        return $this->hasMany(ProductFilter::class);
    }

    /** Rails: `has_many :rate_cards`. */
    public function rateCards(): HasMany
    {
        return $this->hasMany(RateCard::class);
    }

    /** Rails: `has_many :plan_applied_rate_cards, through: :rate_cards`. */
    public function planAppliedRateCards(): HasManyThrough
    {
        return $this->hasManyThrough(PlanRateCard::class, RateCard::class);
    }

    /** Rails: `has_many :contract_applied_rate_cards, through: :rate_cards`. */
    public function contractAppliedRateCards(): HasManyThrough
    {
        return $this->hasManyThrough(ContractRateCard::class, RateCard::class);
    }

    // -- Enum helpers ---------------------------------------------------------

    /** The raw PG enum label, readable on unsaved records too. */
    public function rawProductType(): ?string
    {
        $value = $this->getAttributes()['product_type'] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** Rails: `metered?`. */
    public function metered(): bool
    {
        return $this->rawProductType() === ProductType::Metered->value;
    }

    /** Rails: `fixed?`. */
    public function fixed(): bool
    {
        return $this->rawProductType() === ProductType::Fixed->value;
    }

    // -- Domain methods (ports of the Rails instance methods) ------------------

    /** Rails: `invoice_name` — the invoice display name falls back to the name. */
    public function invoiceName(): string
    {
        return ($this->invoice_display_name ?: $this->name) ?? '';
    }

    /** Rails: CatalogAttachable — a card on a plan or a contract attaches the item. */
    public function attachedToPlanOrSubscription(): bool
    {
        return $this->planAppliedRateCards()->exists() || $this->contractAppliedRateCards()->exists();
    }

    /**
     * Rails: scope `in_categories` — filters by product_category, treating
     * "no category" as a selectable value: with both, the chosen categories
     * OR uncategorized; with only the flag, uncategorized only; otherwise
     * the chosen categories.
     *
     * @param  list<string>|null  $categoryIds
     */
    public function scopeInCategories(Builder $query, ?array $categoryIds, bool $includeUncategorized = false): Builder
    {
        if ($categoryIds !== null && $categoryIds !== [] && $includeUncategorized) {
            return $query->where(fn (Builder $q) => $q
                ->whereIn('product_category_id', $categoryIds)
                ->orWhereNull('product_category_id'));
        }

        if ($includeUncategorized) {
            return $query->whereNull('product_category_id');
        }

        return $query->whereIn('product_category_id', $categoryIds ?? []);
    }

    // -- Validations ------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->name ?? '') === '') {
            $errors['name'] = ['value_is_mandatory'];
        }

        $this->validateCode($errors);

        $productType = $this->rawProductType();
        if ($productType === null || ! in_array($productType, self::PRODUCT_TYPES, true)) {
            $errors['product_type'] = ['value_is_invalid'];
        }

        $this->validateBillableMetricPresence($errors);

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

        // Rails: uniqueness scope: :organization_id, conditions: deleted_at IS NULL.
        $uniqueness = static::query()
            ->where('code', $this->code)
            ->where('organization_id', $this->organization_id)
            ->whereNull('deleted_at');

        if ($this->exists) {
            $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($uniqueness->exists()) {
            $errors['code'] = ['value_already_exist'];
        }
    }

    /**
     * Rails: `validate_billable_metric_presence` — a metered product prices
     * through a billable metric, a fixed one must not carry one.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateBillableMetricPresence(array &$errors): void
    {
        $hasBillableMetric = ($this->billable_metric_id ?? null) !== null;

        if ($this->metered() && ! $hasBillableMetric) {
            $errors['billable_metric'] = ['value_is_mandatory'];
        } elseif ($this->fixed() && $hasBillableMetric) {
            $errors['billable_metric'] = ['value_must_be_blank'];
        }
    }

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
        ];
    }
}
