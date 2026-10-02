<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\JsonbProperties;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Services\Charges\Validators\ChargeModelPropertiesValidator;

/**
 * Frozen-schema model for `fixed_charges` (Rails' FixedCharge).
 *
 * NOTE: `charge_model` is a NATIVE Postgres enum storing the strings
 * 'standard' | 'graduated' | 'volume' — not an integer like charges.
 */
#[Fillable([
    'organization_id',
    'plan_id',
    'add_on_id',
    'parent_id',
    'charge_model',
    'properties',
    'invoice_display_name',
    'pay_in_advance',
    'prorated',
    'units',
    'code',
])]
#[Table(name: 'fixed_charges')]
class FixedCharge extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: FixedCharge::CHARGE_MODELS (native enum values). */
    public const CHARGE_MODELS = ['standard', 'graduated', 'volume'];

    /** Rails schema defaults, mirrored for new instances (see Plan/Customer). */
    protected $attributes = [
        'pay_in_advance' => false,
        'prorated' => false,
        'units' => '0',
    ];

    // -- Relationships ---------------------------------------------------------

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Rails: `has_many :applied_taxes, class_name: "FixedCharge::AppliedTax"`. */
    public function appliedTaxes(): HasMany
    {
        return $this->hasMany(FixedChargeTax::class, 'fixed_charge_id');
    }

    /** Rails: `has_many :taxes, through: :applied_taxes`. */
    public function taxes(): HasManyThrough
    {
        return $this->hasManyThrough(
            Tax::class,
            FixedChargeTax::class,
            'fixed_charge_id',
            'id',
            'id',
            'tax_id',
        );
    }

    public function standard(): bool
    {
        return $this->charge_model === 'standard';
    }

    public function graduated(): bool
    {
        return $this->charge_model === 'graduated';
    }

    public function volume(): bool
    {
        return $this->charge_model === 'volume';
    }

    public function payInAdvance(): bool
    {
        return (bool) $this->pay_in_advance;
    }

    public function proratedCharge(): bool
    {
        return (bool) $this->prorated;
    }

    /** Rails: `equal_properties?`. */
    public function equalProperties(self $other): bool
    {
        return $this->charge_model === $other->charge_model
            && $this->properties === $other->properties
            && $this->units === $other->units;
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

        $units = $this->units;

        if ($units === null) {
            // Rails: numericality on a nil value fails with :blank.
            $errors['units'] = ['value_is_mandatory'];
        } elseif ((float) $units < 0) {
            // Rails: numericality greater_than_or_equal_to 0.
            $errors['units'] = ['value_is_out_of_range'];
        }

        $chargeModel = $this->charge_model;

        if ($chargeModel === null || $chargeModel === '') {
            $errors['charge_model'] = ['value_is_mandatory'];
        } elseif (! in_array($chargeModel, self::CHARGE_MODELS, true)) {
            $errors['charge_model'] = ['value_is_invalid'];
        }

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];
        }

        if ($this->pay_in_advance === null) {
            $errors['pay_in_advance'] = ['value_is_reserved'];
        }

        if ($this->prorated === null) {
            $errors['prorated'] = ['value_is_reserved'];
        }

        $properties = $this->properties;

        if ($properties === null || $properties === [] || $properties === '') {
            $errors['properties'] = ['value_is_mandatory'];
        }

        // Rails: validate_code_unique — only parent fixed charges are checked.
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

        if ($this->payInAdvance() && $this->volume()) {
            $errors['pay_in_advance'] = ['invalid_charge_model'];
        }

        if ($this->proratedCharge() && $this->graduated() && $this->payInAdvance()) {
            $errors['prorated'] = ['invalid_charge_model'];
        }

        if (is_array($properties) && $properties !== []) {
            $propertyErrors = ChargeModelPropertiesValidator::validate(
                $this->charge_model,
                $properties,
                chargeable: $this,
            );

            if ($propertyErrors !== []) {
                $errors['properties'] = $propertyErrors;
            }
        }

        return $errors;
    }

    // -- Enum helpers (Rails enum suffix methods) -------------------------------

    /** Rails: `scope :parents, -> { where(parent_id: nil) }`. */
    #[Scope]
    protected function parents(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('parent_id');
    }

    protected function casts(): array
    {
        return [
            'properties' => JsonbProperties::class,
            'pay_in_advance' => 'boolean',
            'prorated' => 'boolean',
            'units' => 'string',
        ];
    }
}
