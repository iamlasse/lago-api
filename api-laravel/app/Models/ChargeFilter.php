<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\JsonbProperties;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Services\Charges\Validators\ChargeModelPropertiesValidator;

/**
 * Frozen-schema model for `charge_filters` (Rails' ChargeFilter).
 */
#[Fillable([
    'charge_id',
    'properties',
    'invoice_display_name',
    'organization_id',
    'code',
])]
#[Table(name: 'charge_filters')]
class ChargeFilter extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: ChargeFilter::CODE_SLUG_LIMIT. */
    public const CODE_SLUG_LIMIT = 200;

    // -- Code generation -----------------------------------------------------------

    /**
     * Rails: ChargeFilter.generate_code — a stable slug of the values hash,
     * suffixed with a short SHA of the canonical form.
     *
     * @param  array<string, list<string>>  $valuesHash
     */
    public static function generateCode(array $valuesHash): string
    {
        ksort($valuesHash);

        $canonicalParts = [];

        foreach ($valuesHash as $key => $filterValues) {
            $sorted = (array) $filterValues;
            sort($sorted);
            $canonicalParts[] = $key.':'.implode('+', $sorted);
        }

        $canonical = implode('|', $canonicalParts);

        return self::parameterize($canonical).'_'.mb_substr(hash('sha256', $canonical), 0, 8);
    }

    /** Add a suffix if duplicated, only for new ones (Rails: unique_code_for). */
    public static function uniqueCodeFor(?string $chargeId, string $baseCode): string
    {
        if ($chargeId === null) {
            return $baseCode;
        }

        $taken = static::query()
            ->where('charge_id', $chargeId)
            ->withoutGlobalScope('keptOrdered')
            ->pluck('code')
            ->filter()
            ->all();

        return self::nextFreeCode($baseCode, $taken);
    }

    /**
     * Callers that create several filters at once hold their own set, since
     * the codes they hand out are not in the table yet.
     *
     * @param  iterable<string>  $taken
     */
    public static function nextFreeCode(string $baseCode, iterable $taken): string
    {
        $takenSet = [];
        foreach ($taken as $code) {
            $takenSet[$code] = true;
        }

        if (! isset($takenSet[$baseCode])) {
            return $baseCode;
        }

        $suffix = 2;

        while (isset($takenSet[$baseCode.'_'.$suffix])) {
            $suffix++;
        }

        return $baseCode.'_'.$suffix;
    }

    // -- Relationships -----------------------------------------------------------

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    /** Rails: `has_many :values, class_name: "ChargeFilterValue", dependent: :destroy`. */
    public function values(): HasMany
    {
        return $this->hasMany(ChargeFilterValue::class);
    }

    /** Rails: `has_many :billable_metric_filters, through: :values`. */
    public function billableMetricFilters(): HasManyThrough
    {
        return $this->hasManyThrough(
            BillableMetricFilter::class,
            ChargeFilterValue::class,
            'charge_filter_id',
            'id',
            'id',
            'billable_metric_filter_id',
        );
    }

    /** Rails: `has_one :billable_metric, through: :charge`. */
    public function billableMetric()
    {
        return $this->charge->billableMetric();
    }

    // -- Instance helpers ------------------------------------------------------------

    /**
     * Rails: `display_name(separator: ", ")` — the invoice display name, or
     * the joined values.
     */
    public function displayName(string $separator = ', '): string
    {
        if ($this->invoice_display_name !== null && $this->invoice_display_name !== '') {
            return $this->invoice_display_name;
        }

        $parts = [];

        foreach ($this->values as $value) {
            if ($value->values === [ChargeFilterValue::ALL_FILTER_VALUES]) {
                $parts[] = $value->key();

                continue;
            }

            $parts[] = $value->values;
        }

        return implode($separator, array_merge(...array_map(fn ($part) => (array) $part, $parts ?: [[]])));
    }

    /** Rails: `to_h` — {billable_metric_filter.key => [values]}. */
    public function toH(): array
    {
        $result = [];

        foreach ($this->values as $filterValue) {
            $result[$filterValue->key()] = $filterValue->values;
        }

        return $result;
    }

    /** Rails: `to_h_with_all_values` — ALL_FILTER_VALUES expands to the metric's values. */
    public function toHWithAllValues(): array
    {
        $result = [];

        foreach ($this->values as $filterValue) {
            $values = $filterValue->values;

            if ($values === [ChargeFilterValue::ALL_FILTER_VALUES]) {
                $values = $filterValue->billableMetricFilter?->values ?? $values;
            }

            $result[$filterValue->key()] = $values;
        }

        return $result;
    }

    public function assignCode(): void
    {
        if ($this->code !== null && $this->code !== '') {
            return;
        }

        $baseCode = static::generateCode($this->toH());

        $this->forceFill(['code' => static::uniqueCodeFor($this->charge_id, $baseCode)])->saveQuietly();
    }

    public function pricingGroupKeys(): mixed
    {
        $properties = is_array($this->properties) ? $this->properties : [];

        return $properties['pricing_group_keys'] ?? null
            ?? $properties['grouped_by'] ?? null;
    }

    // -- Validations --------------------------------------------------------------------

    /**
     * Port of ChargeFilter#validate_properties — the charge's charge-model
     * property matrix applied to the filter's properties.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        $properties = $this->properties;
        $chargeModel = $this->charge?->getRawOriginal('charge_model');

        if (is_array($properties) && $properties !== [] && $chargeModel !== null) {
            $propertyErrors = ChargeModelPropertiesValidator::validate(
                $chargeModel,
                $properties,
                $this,
            );

            if ($propertyErrors !== []) {
                $errors['properties'] = $propertyErrors;
            }
        }

        return $errors;
    }

    /**
     * NOTE: Ensure filters are keeping the initial ordering
     * (Rails default_scope -> { kept.order(updated_at: :asc) }).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('keptOrdered', fn (Builder $query) => $query->oldest('updated_at'));
    }

    protected function casts(): array
    {
        return [
            'properties' => JsonbProperties::class,
        ];
    }

    /**
     * Rails' `String#parameterize(separator: "_")` for the code slug — ASCII
     * transliteration is approximated with iconv, non-alphanumerics collapse
     * to the separator.
     */
    private static function parameterize(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if ($transliterated === false) {
            $transliterated = $value;
        }

        $slug = mb_strtolower($transliterated);
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = mb_trim($slug, '_');

        return mb_substr($slug, 0, self::CODE_SLUG_LIMIT);
    }
}
