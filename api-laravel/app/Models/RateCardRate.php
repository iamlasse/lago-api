<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\RateCardRateBillingIntervalUnit;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Frozen-schema model for `rate_card_rates`. Port of the Rails
 * RateCardRate model (app/models/rate_card_rate.rb): one entry of a rate
 * card's append-only pricing timeline.
 */
#[Fillable([
    'organization_id',
    'rate_card_id',
    'code',
    'effective_from',
    'rate_model',
    'rate_properties',
    'min_amount_cents',
    'billing_interval_count',
    'billing_interval_unit',
    'applied_pricing_unit_conversion_rate',
])]
#[Table(name: 'rate_card_rates')]
class RateCardRate extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: CODE_FORMAT (CatalogCodeFormat concern). */
    public const CODE_FORMAT = '/\A(?!\.+\z)[a-zA-Z0-9_\-.]+\z/';

    /** Rails: RATE_MODELS — mirrors the rate_card_rate_model PG enum. */
    public const RATE_MODELS = [
        'standard' => 'standard',
        'graduated' => 'graduated',
        'package' => 'package',
        'percentage' => 'percentage',
        'volume' => 'volume',
        'graduated_percentage' => 'graduated_percentage',
        'custom' => 'custom',
        'dynamic' => 'dynamic',
    ];

    /** Rails: BILLING_INTERVAL_UNITS. */
    public const BILLING_INTERVAL_UNITS = ['day' => 'day', 'week' => 'week', 'month' => 'month', 'year' => 'year'];

    /** Rails: STATUSES — derived from the card's timeline, never stored. */
    public const STATUSES = ['pending' => 'pending', 'active' => 'active', 'terminated' => 'terminated'];

    /** NOT NULL columns with DB defaults, mirrored on new instances. */
    protected $attributes = [
        'rate_properties' => '{}',
        'min_amount_cents' => 0,
        'billing_interval_count' => 1,
    ];

    /**
     * Rails enum assignments with unknown names are recorded here and fail
     * the inclusion validation instead of raising.
     *
     * @var array<string, true>
     */
    protected array $invalidEnumAssignments = [];

    // -- Relationships --------------------------------------------------------

    /** Rails: `belongs_to :organization`. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: `belongs_to :rate_card`. */
    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }

    /** Rails: `has_many :fees`. */
    public function fees(): HasMany
    {
        return $this->hasMany(Fee::class);
    }

    // -- Scopes ---------------------------------------------------------------

    /** Rails: `scope :pending` — `where("effective_from > ?", Time.current)`. */
    public function scopePending($query)
    {
        return $query->where('effective_from', '>', now());
    }

    /** Rails: `scope :effective` — `where(effective_from: ..Time.current)`. */
    public function scopeEffective($query)
    {
        return $query->where('effective_from', '<=', now());
    }

    // -- Domain methods (ports of the Rails instance methods) ------------------

    /**
     * Rails: `status` — derived from the card's append-only timeline: the
     * latest effective rate is active, future rates are pending, and earlier
     * effective rates have been superseded (terminated).
     */
    public function status(): string
    {
        $effectiveFrom = Carbon::parse((string) $this->effective_from, 'UTC');

        if ($effectiveFrom->gt(now())) {
            return self::STATUSES['pending'];
        }

        $superseded = $this->rateCard->rates()
            ->whereNull('deleted_at')
            ->where('effective_from', '>', $effectiveFrom)
            ->where('effective_from', '<=', now())
            ->whereKeyNot($this->getKey())
            ->exists();

        return $superseded ? self::STATUSES['terminated'] : self::STATUSES['active'];
    }

    /** Rails: `pending?`. */
    public function isPending(): bool
    {
        return $this->status() === self::STATUSES['pending'];
    }

    /** Rails: `active?`. */
    public function isActive(): bool
    {
        return $this->status() === self::STATUSES['active'];
    }

    /** Rails: `terminated?`. */
    public function isTerminated(): bool
    {
        return $this->status() === self::STATUSES['terminated'];
    }

    /**
     * Rails: `billable_metric` — the property validators are shared with v1
     * charges and read the metric off the record; expose the card's item
     * metric under the same name.
     */
    public function billableMetric(): ?BillableMetric
    {
        return $this->rateCard->product->billableMetric;
    }

    /**
     * Rails: `properties` — the charge validators read pricing data from a
     * `properties` attribute; expose the rate's properties under that name.
     *
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return (array) ($this->rate_properties ?? []);
    }

    // -- Validations ------------------------------------------------------------

    /**
     * Port of the Rails validations — `field => [api error codes]`, empty
     * when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        $this->validateCode($errors);
        $this->validateEffectiveFrom($errors);
        $this->validateRateModel($errors);
        $this->validateBillingIntervalUnit($errors);
        $this->validateMinAmountCents($errors);
        $this->validateBillingIntervalCount($errors);
        $this->validatePricingUnitConversionRate($errors);
        $this->validateEffectiveFromIsAppended($errors);
        $this->validateRateModelCompatibility($errors);
        $this->validateMinAmountTiming($errors);
        $this->validateRateProperties($errors);

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

        // Rails: uniqueness scope: :rate_card_id, conditions: deleted_at IS NULL.
        $uniqueness = static::query()
            ->where('code', $this->code)
            ->where('rate_card_id', $this->rate_card_id)
            ->whereNull('deleted_at');

        if ($this->exists) {
            $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($uniqueness->exists()) {
            $errors['code'] = ['value_already_exist'];
        }
    }

    /**
     * Rails: `validate_effective_from_parseable` — the column is a datetime:
     * an unparseable value casts to nil (caught by the presence validation)
     * or fails the format validation.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateEffectiveFrom(array &$errors): void
    {
        $raw = $this->getAttributes()['effective_from'] ?? $this->getRawOriginal('effective_from');

        if ($raw === null || $raw === '') {
            $errors['effective_from'] = ['value_is_mandatory'];

            return;
        }

        // An unparseable string never reaches the attribute (the cast keeps
        // it null), so the parse check reads the raw value.
        if (is_string($raw) && \App\Support\Utils\Datetime::parseIso8601($raw) === null
            && \App\Support\Utils\Datetime::parseIso8601Date($raw) === null) {
            $errors['effective_from'] = ['value_is_invalid'];
        }
    }

    /**
     * Rails: `enum :rate_model, validate: {allow_nil: true}` — a missing
     * value fails presence only; a provided-but-unknown name fails
     * inclusion.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateRateModel(array &$errors): void
    {
        $rawRateModel = $this->getAttributes()['rate_model'] ?? null;

        if ($rawRateModel === null) {
            $errors['rate_model'] = ['value_is_mandatory'];

            return;
        }

        if (! in_array((string) $rawRateModel, self::RATE_MODELS, true)) {
            $errors['rate_model'] = ['value_is_invalid'];
        }
    }

    /** @param  array<string, list<string>>  $errors */
    protected function validateBillingIntervalUnit(array &$errors): void
    {
        $rawUnit = $this->getAttributes()['billing_interval_unit'] ?? null;

        if ($rawUnit === null) {
            $errors['billing_interval_unit'] = ['value_is_mandatory'];

            return;
        }

        if (! in_array((string) $rawUnit, self::BILLING_INTERVAL_UNITS, true)) {
            $errors['billing_interval_unit'] = ['value_is_invalid'];
        }
    }

    /** Rails: `min_amount_cents >= 0` — @param array<string, list<string>> $errors */
    protected function validateMinAmountCents(array &$errors): void
    {
        $minAmountCents = $this->min_amount_cents;

        if ($minAmountCents !== null && (int) $minAmountCents < 0) {
            $errors['min_amount_cents'] = ['value_is_out_of_range'];
        }
    }

    /** Rails: `billing_interval_count >= 1` — @param array<string, list<string>> $errors */
    protected function validateBillingIntervalCount(array &$errors): void
    {
        $count = $this->billing_interval_count;

        if ($count !== null && (int) $count < 1) {
            $errors['billing_interval_count'] = ['value_is_out_of_range'];
        }
    }

    /**
     * Rails: `validate_pricing_unit_conversion_rate` — a card priced in an
     * applied pricing unit needs the conversion rate on every rate.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validatePricingUnitConversionRate(array &$errors): void
    {
        $card = $this->rateCard;

        if ($card !== null && ($card->applied_pricing_unit_code ?? null) !== null && $this->applied_pricing_unit_conversion_rate === null) {
            $errors['applied_pricing_unit_conversion_rate'] = ['value_is_mandatory'];
        }
    }

    /**
     * Rails: `validate_effective_from_is_appended` — append-only timeline: a
     * new rate's effective_from must be strictly greater than the latest
     * existing rate on the same card. No insertion between rates. The past
     * is immutable, the future is editable.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateEffectiveFromIsAppended(array &$errors): void
    {
        $rawEffectiveFrom = $this->getAttributes()['effective_from'] ?? $this->getRawOriginal('effective_from');
        $effectiveFrom = $rawEffectiveFrom !== null ? Carbon::parse((string) $rawEffectiveFrom, 'UTC') : null;
        $card = $this->rateCard;

        if ($effectiveFrom === null || $card === null) {
            return;
        }

        if ($this->exists && ! $this->isDirty('effective_from')) {
            return;
        }

        $others = $card->rates();

        if ($this->exists) {
            $others->whereKeyNot($this->getKey());
        }

        if ($others->clone()->where('effective_from', $effectiveFrom)->exists()) {
            $errors['effective_from'] = ['value_already_exist'];

            return;
        }

        /** @var string|null $activeBoundary */
        $activeBoundary = $others->clone()->where('effective_from', '<=', now())->max('effective_from');

        if ($activeBoundary === null) {
            return;
        }

        if ($effectiveFrom->lessThanOrEqualTo(Carbon::parse((string) $activeBoundary, 'UTC'))) {
            $errors['effective_from'] = ['must_be_after_active_rate'];
        }
    }

    /**
     * Rails: `validate_rate_model_compatibility` — the v1-parity matrix
     * (RateCardRates::ModelCompatibility).
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateRateModelCompatibility(array &$errors): void
    {
        $errorCode = \App\Services\RateCardRates\ModelCompatibility::errorCode(
            rateModel: ($this->rate_model !== null ? (string) $this->getRawOriginal('rate_model') : null),
            rateCard: $this->rateCard,
        );

        if ($errorCode !== null) {
            $errors['rate_model'] = [$errorCode];
        }
    }

    /**
     * Rails: `validate_min_amount_timing` — a minimum spending true-up runs
     * against a closed period, so it only exists on arrears cards.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateMinAmountTiming(array &$errors): void
    {
        if ((int) ($this->min_amount_cents ?? 0) <= 0) {
            return;
        }

        if ($this->rateCard?->advance()) {
            $errors['min_amount_cents'] = ['not_allowed_for_billing_timing'];
        }
    }

    /**
     * Rails: `validate_properties` — the per-model property validators
     * shared with v1 charges; their error codes land on :rate_properties.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function validateRateProperties(array &$errors): void
    {
        if ($this->rate_model === null || isset($errors['rate_model'])) {
            return;
        }

        // TODO(port): the per-model property validators take a Charge-like
        // record; RateCardRate (like RateOverride) needs the shared
        // RateProperties validators once they are extracted from the charge
        // layer. Properties pass through unvalidated for now.
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'rate_properties' => 'array',
            'min_amount_cents' => 'integer',
            'billing_interval_count' => 'integer',
            'billing_interval_unit' => RateCardRateBillingIntervalUnit::class,
            'applied_pricing_unit_conversion_rate' => 'string',
        ];
    }
}
