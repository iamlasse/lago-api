<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Casts\JsonbProperties;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Port of Rails' Entitlement::Privilege (app/models/entitlement/privilege.rb)
 * — a parametrizable attribute of a feature (code, value type and, for
 * `select`, the allowed options in `config`). Stored in
 * `entitlement_privileges`; `value_type` is the PG enum
 * `entitlement_privilege_value_types` (the column holds the label).
 */
#[Fillable([
    'organization_id',
    'entitlement_feature_id',
    'code',
    'name',
    'value_type',
    'config',
    'deleted_at',
])]
#[Table(name: 'entitlement_privileges')]
class Privilege extends BaseModel
{
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    /** Rails: VALUE_TYPES — the PG enum labels, in order. */
    public const VALUE_TYPES = ['integer', 'string', 'boolean', 'select'];

    /**
     * Rails' ActiveRecord carries the schema's column defaults in every new
     * instance; Eloquent does not.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'value_type' => 'string',
        // NOTE: no config default here — the jsonb column default ({} and,
        // with the 'array' cast, a PHP [] on read) must come from the
        // database; a string default would be re-encoded by the cast.
    ];

    // -- Relationships ------------------------------------------------------------

    /** Rails: `belongs_to :feature, foreign_key: :entitlement_feature_id`. */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'entitlement_feature_id');
    }

    /** Rails: `has_many :values, foreign_key: :entitlement_privilege_id, dependent: :destroy`. */
    public function values(): HasMany
    {
        return $this->hasMany(EntitlementValue::class, 'entitlement_privilege_id');
    }

    // Rails: `has_many :entitlements, through: :values` — never read on its
    // own by the ported services (values carry the entitlement ids).

    // -- Validations ----------------------------------------------------------------

    /**
     * Port of the Rails model validations (including the
     * `validate :validate_config` custom check) — `field => [api error
     * codes]`, empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        if (($this->code ?? '') === '') {
            $errors['code'] = ['value_is_mandatory'];
        } elseif (mb_strlen((string) $this->code) > 255) {
            $errors['code'] = ['value_is_too_long'];
        }

        if ($this->name !== null && mb_strlen((string) $this->name) > 255) {
            $errors['name'] = ['value_is_too_long'];
        }

        if (($this->value_type ?? '') === '') {
            $errors['value_type'] = ['value_is_mandatory'];
        } elseif (! in_array($this->value_type, self::VALUE_TYPES, true)) {
            $errors['value_type'] = ['value_is_invalid'];
        }

        if (! $this->configValid()) {
            $errors['config'] = ['invalid_format'];
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'config' => JsonbProperties::class,
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Rails: `config_valid?` — config is only used for the `select`
     * value_type, and it must contain a non-empty list of string
     * select_options; every other value_type requires a blank config.
     */
    private function configValid(): bool
    {
        $config = $this->config;

        if ($this->value_type === 'select') {
            if (! is_array($config) || array_keys($config) !== ['select_options']) {
                return false;
            }

            $options = $config['select_options'];

            if (! is_array($options) || $options === []) {
                return false;
            }

            foreach ($options as $option) {
                if (! is_string($option)) {
                    return false;
                }
            }

            return true;
        }

        return $config === null || $config === [];
    }
}
