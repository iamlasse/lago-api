<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChargeModel;
use App\Models\Casts\JsonbProperties;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Charges\AggregationChecks;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Services\Charges\Validators\ChargeModelPropertiesValidator;

/**
 * Frozen-schema model for `charges` — refined with the Rails Charge model's
 * relations, enums, scopes and validations (app/models/charge.rb).
 */
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'billable_metric_id',
    'plan_id',
    'amount_currency',
    'charge_model',
    'properties',
    'pay_in_advance',
    'min_amount_cents',
    'invoiceable',
    'prorated',
    'invoice_display_name',
    'regroup_paid_fees',
    'parent_id',
    'organization_id',
    'code',
    'accepts_target_wallet',
])]
#[\Illuminate\Database\Eloquent\Attributes\Table(name: 'charges')]
class Charge extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: Charge::CHARGE_MODELS — integer enum, see App\Enums\ChargeModel. */
    public const CHARGE_MODELS = [
        'standard', 'graduated', 'package', 'percentage',
        'volume', 'graduated_percentage', 'custom', 'dynamic',
    ];

    /** Rails: Charge::REGROUPING_PAID_FEES_OPTIONS (invoice = 0). */
    public const REGROUPING_PAID_FEES_OPTIONS = ['invoice'];

    /** Rails: Charge::EVENT_TARGET_WALLET_CODE. */
    public const EVENT_TARGET_WALLET_CODE = 'target_wallet_code';

    /** Rails schema defaults, mirrored for new instances. */
    protected $attributes = [
        'charge_model' => 0,
        'pay_in_advance' => false,
        'min_amount_cents' => 0,
        'invoiceable' => true,
        'prorated' => false,
        'accepts_target_wallet' => false,
    ];

    // -- Relationships ---------------------------------------------------------

    public function plan(): BelongsTo
    {
        // Rails: belongs_to :plan, -> { with_discarded }, touch: true
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function billableMetric(): BelongsTo
    {
        // Rails: belongs_to :billable_metric, -> { with_discarded }
        return $this->belongsTo(BillableMetric::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Rails: `has_many :filters, dependent: :destroy, class_name: "ChargeFilter"`. */
    public function filters(): HasMany
    {
        return $this->hasMany(ChargeFilter::class);
    }

    /** Rails: `has_many :filter_values, through: :filters, source: :values`. */
    public function filterValues(): HasManyThrough
    {
        return $this->hasManyThrough(
            ChargeFilterValue::class,
            ChargeFilter::class,
            'charge_id',
            'charge_filter_id',
        );
    }

    /** Rails: `has_many :applied_taxes, class_name: "Charge::AppliedTax"`. */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(ChargeTax::class, 'charge_id');
    }

    /** Rails: `has_many :taxes, through: :applied_taxes`. */
    public function taxes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Tax::class,
            ChargeTax::class,
            'charge_id',
            'id',
            'id',
            'tax_id',
        );
    }

    // -- Scopes ----------------------------------------------------------------

    /** Rails: `scope :pay_in_advance, -> { where(pay_in_advance: true) }`. */
    public function scopePayInAdvance(Builder $query): Builder
    {
        return $query->where('pay_in_advance', true);
    }

    /** Rails: `scope :parents, -> { where(parent_id: nil) }`. */
    public function scopeParents(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    // -- Enum helpers (Rails enum suffix methods) --------------------------------

    public function standard(): bool
    {
        return $this->charge_model === ChargeModel::Standard->value;
    }

    public function graduated(): bool
    {
        return $this->charge_model === ChargeModel::Graduated->value;
    }

    public function package(): bool
    {
        return $this->charge_model === ChargeModel::Package->value;
    }

    public function percentage(): bool
    {
        return $this->charge_model === ChargeModel::Percentage->value;
    }

    public function volume(): bool
    {
        return $this->charge_model === ChargeModel::Volume->value;
    }

    public function graduatedPercentage(): bool
    {
        return $this->charge_model === ChargeModel::GraduatedPercentage->value;
    }

    public function custom(): bool
    {
        return $this->charge_model === ChargeModel::Custom->value;
    }

    public function dynamic(): bool
    {
        return $this->charge_model === ChargeModel::Dynamic->value;
    }

    public function payInAdvance(): bool
    {
        return (bool) $this->pay_in_advance;
    }

    public function invoiceableCharge(): bool
    {
        return (bool) $this->invoiceable;
    }

    public function proratedCharge(): bool
    {
        return (bool) $this->prorated;
    }

    /** Rails: `pricing_group_keys` (grouped_by deprecation fallback). */
    public function pricingGroupKeys(): mixed
    {
        $properties = is_array($this->properties) ? $this->properties : [];

        return $properties['pricing_group_keys'] ?? null
            ?? $properties['grouped_by'] ?? null;
    }

    /** Rails: `equal_properties?`. */
    public function equalProperties(self $other): bool
    {
        return $this->charge_model === $other->charge_model
            && $this->properties === $other->properties;
    }

    // -- Validations -------------------------------------------------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid. Error symbols map through Rails' en.yml
     * activerecord.errors.messages table to the API codes.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        $chargeModel = $this->charge_model;

        if ($chargeModel === null) {
            $errors['charge_model'] = ['value_is_mandatory'];
        } elseif (ChargeModel::tryFrom($chargeModel) === null) {
            // Rails: enum :charge_model, CHARGE_MODELS, validate: true (inclusion).
            $errors['charge_model'] = ['value_is_invalid'];
        }

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];
        }

        if ($this->min_amount_cents !== null && $this->min_amount_cents < 0) {
            $errors['min_amount_cents'] = ['value_is_out_of_range'];
        }

        // Rails: belongs_to :billable_metric (required by default).
        if ($this->billable_metric_id === null) {
            $errors['billable_metric'] = ['value_is_mandatory'];
        }

        if ($this->isDirty('billable_metric_id')) {
            $this->unsetRelation('billableMetric');
        }

        $billableMetric = $this->billableMetric;

        // Rails: validate_code_unique — only parent charges are checked.
        if ($this->plan_id !== null && $this->parent_id === null && ($this->code ?? '') !== '') {
            $uniqueness = static::query()
                ->where('plan_id', $this->plan_id)
                ->whereNull('parent_id')
                ->where('code', $this->code)
                ->whereNull('deleted_at');

            if ($this->exists) {
                $uniqueness->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($uniqueness->exists()) {
                $errors['code'] = ['value_already_exist'];
            }
        }

        if ($this->graduatedPercentage() && ! $this->premium()) {
            // Rails: charge_model_allowance.
            $errors['charge_model'] = ['graduated_percentage_requires_premium_license'];
        }

        if ($this->dynamic() && ! AggregationChecks::isSum($billableMetric)) {
            // Only sum aggregation is compatible with Dynamic Pricing for now.
            $errors['charge_model'] = ['invalid_aggregation_type_or_charge_model'];
        }

        if ($this->custom() && ! AggregationChecks::isCustom($billableMetric)) {
            $errors['charge_model'] = ['invalid_aggregation_type_or_charge_model'];
        }

        if ($this->payInAdvance()) {
            if ($this->volume() || ! AggregationChecks::isPayableInAdvance($billableMetric)) {
                $errors['pay_in_advance'] = ['invalid_aggregation_type_or_charge_model'];
            }

            if ($this->min_amount_cents !== null && $this->min_amount_cents > 0) {
                $errors['min_amount_cents'] = ['not_compatible_with_pay_in_advance'];
            }
        }

        // NOTE: regroup_paid_fees only works with pay_in_advance and
        // non-invoiceable charges.
        if ($this->regroup_paid_fees !== null && ! ($this->payInAdvance() && ! $this->invoiceableCharge())) {
            $errors['regroup_paid_fees'] = ['only_compatible_with_pay_in_advance_and_non_invoiceable'];
        }

        if (! $this->payInAdvance() && ! $this->invoiceableCharge()) {
            $errors['invoiceable'] = ['must_be_true_unless_pay_in_advance'];
        }

        if ($this->proratedCharge()) {
            if (! AggregationChecks::isWeightedSum($billableMetric)) {
                $recurring = (bool) $billableMetric?->recurring;

                $allowed = ($recurring && $this->payInAdvance() && $this->standard())
                    || ($recurring && ! $this->payInAdvance() && ($this->standard() || $this->volume() || $this->graduated()));

                if (! $allowed) {
                    $errors['prorated'] = ['invalid_billable_metric_or_charge_model'];
                }
            }
        }

        if ((bool) $this->accepts_target_wallet
            && ! $this->eventsTargetingWalletsEnabled()) {
            $errors['accepts_target_wallet'] = ['feature_unavailable'];
        }

        // Rails: validate_properties — flattened into errors[:properties].
        if (is_array($this->properties) && $this->properties !== [] && $chargeModel !== null) {
            $propertyErrors = ChargeModelPropertiesValidator::validate(
                $chargeModel,
                $this->properties,
                $this,
            );

            if ($propertyErrors !== []) {
                $errors['properties'] = $propertyErrors;
            }
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'charge_model' => 'integer',
            'properties' => JsonbProperties::class,
            'pay_in_advance' => 'boolean',
            'min_amount_cents' => 'integer',
            'invoiceable' => 'boolean',
            'prorated' => 'boolean',
            'regroup_paid_fees' => 'integer',
            'accepts_target_wallet' => 'boolean',
        ];
    }

    /**
     * Rails: `charge_model=` assigns the enum NAME ("standard"…) and the
     * column stores the integer position. Unmapped values pass through raw
     * so the validation reports them (never silently coerced).
     */
    protected function chargeModel(): Attribute
    {
        return Attribute::set(function ($value) {
            if ($value === null) {
                return null;
            }

            return ChargeModel::fromOption($value) ?? $value;
        });
    }

    /**
     * Rails: `organization.events_targeting_wallets_enabled?` — a premium
     * integration flag.
     */
    protected function eventsTargetingWalletsEnabled(): bool
    {
        $integrations = $this->organization?->premium_integrations ?? [];

        return $this->premium() && in_array('events_targeting_wallets', (array) $integrations, true);
    }

    /** Rails: License.premium? (see App\Services\BaseService::premium). */
    protected function premium(): bool
    {
        return env('LAGO_LICENSE') !== null && env('LAGO_LICENSE') !== '';
    }
}
