<?php

declare(strict_types=1);

namespace App\Models\IntegrationMappings;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Port of Rails' IntegrationMappings::BaseMapping (app/models/
 * integration_mappings/base_mapping.rb) — one Lago object (an add-on or a
 * billable metric) mapped onto an external accounting code for one
 * integration, optionally scoped to a billing entity.
 *
 * Rails stores STI type strings in the frozen `type` column as the Rails
 * class name ("IntegrationMappings::NetsuiteMapping"); the subclasses
 * re-scope every query with it (Rails STI does this implicitly).
 *
 * TODO(port): Rails' PaperTrailTraceable concern — the `versions` audit
 * trail is not ported.
 */
#[Fillable([
    'integration_id',
    'mappable_type',
    'mappable_id',
    'type',
    'settings',
    'organization_id',
    'billing_entity_id',
])]
#[Table(name: 'integration_mappings')]
class BaseMapping extends BaseModel
{
    /** Rails: the subclass `type` strings (STI — never renumber/rename). */
    public const NETSUITE_TYPE = 'IntegrationMappings::NetsuiteMapping';

    public const ANROK_TYPE = 'IntegrationMappings::AnrokMapping';

    public const AVALARA_TYPE = 'IntegrationMappings::AvalaraMapping';

    public const XERO_TYPE = 'IntegrationMappings::XeroMapping';

    /** Rails: MAPPABLE_TYPES. */
    public const MAPPABLE_TYPES = ['AddOn', 'BillableMetric'];

    /** Rails STI: stored `type` strings → the hydrating subclass. */
    protected const STI_CLASSES = [
        self::NETSUITE_TYPE => NetsuiteMapping::class,
        self::ANROK_TYPE => AnrokMapping::class,
        self::AVALARA_TYPE => AvalaraMapping::class,
        self::XERO_TYPE => XeroMapping::class,
    ];

    /**
     * Rails STI: hydrating a row instantiates the subclass named by the
     * stored `type` column (Laravel has no STI of its own), so the
     * subclass settings accessors resolve on query-loaded rows.
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class = self::STI_CLASSES[$attributes['type'] ?? ''] ?? null;

        if ($class === null || $class === static::class) {
            return parent::newFromBuilder($attributes, $connection);
        }

        $model = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?? $this->getConnectionName());

        return $model;
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Integration::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }

    public function billingEntity(): BelongsTo
    {
        return $this->belongsTo(\App\Models\BillingEntity::class);
    }

    /**
     * Rails: `belongs_to :mappable, polymorphic: true` — the stored
     * mappable_type values are Rails class names ("AddOn",
     * "BillableMetric"), resolved into App\Models.
     */
    public function mappable(): ?object
    {
        $class = 'App\Models\\'.$this->mappable_type;

        if (! class_exists($class)) {
            return null;
        }

        return $class::query()->find($this->mappable_id);
    }

    // -- SettingsStorable (app/models/concerns/settings_storable.rb) ---------

    public function getFromSettings(?string $key): mixed
    {
        if ($key === null) {
            return null;
        }

        return data_get($this->settings, $key);
    }

    /** Rails: `push_to_settings(key:, value:)`. */
    public function pushToSettings(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        $settings[$key] = $value;
        $this->settings = $settings;
    }
    // Settings-backed accessors stay classic get/set mutators: an
    // Attribute::make(set:) closure that only mutates $this->attributes as a
    // side effect gets clobbered — Eloquent snapshots the attributes before
    // invoking the setter and overwrites them with array_merge(snapshot,
    // return), so returning [] discards the pushToSettings write.

    public function getExternalIdAttribute(): mixed
    {
        return $this->getFromSettings('external_id');
    }

    public function setExternalIdAttribute(mixed $value): void
    {
        $this->pushToSettings('external_id', $value);
    }

    public function getExternalAccountCodeAttribute(): mixed
    {
        return $this->getFromSettings('external_account_code');
    }

    public function setExternalAccountCodeAttribute(mixed $value): void
    {
        $this->pushToSettings('external_account_code', $value);
    }

    public function getExternalNameAttribute(): mixed
    {
        return $this->getFromSettings('external_name');
    }

    public function setExternalNameAttribute(mixed $value): void
    {
        $this->pushToSettings('external_name', $value);
    }

    // -- Validations (Rails: validates / validate blocks) --------------------

    /**
     * Port of the Rails model validations — `field => [api error codes]`,
     * empty when valid.
     *
     * @return array<string, list<string>>
     */
    public function validateAttributes(): array
    {
        $errors = [];

        // validates :mappable_type, inclusion: {in: MAPPABLE_TYPES.map(&:to_s)}
        if (! in_array($this->mappable_type, self::MAPPABLE_TYPES, true)) {
            $errors['mappable_type'] = ['value_is_invalid'];
        }

        // validates :mappable_type, uniqueness: {scope: [:mappable_id,
        // :integration_id, :organization_id, :billing_entity_id]}.
        $scope = static::query()
            ->where('mappable_type', $this->mappable_type)
            ->where('mappable_id', $this->mappable_id)
            ->where('integration_id', $this->integration_id)
            ->where('organization_id', $this->organization_id)
            ->where('billing_entity_id', $this->billing_entity_id);

        if ($this->exists) {
            $scope->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($scope->exists()) {
            $errors['mappable_type'] = ['value_already_exist'];
        }

        // validate :validate_billing_entity_organization.
        if ($this->billingEntity !== null && $this->billingEntity->organization_id !== $this->organization_id) {
            $errors['billing_entity'] = ['must belong to the same organization'];
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
