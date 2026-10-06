<?php

declare(strict_types=1);

namespace App\Models\IntegrationCollectionMappings;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Port of Rails' IntegrationCollectionMappings::BaseCollectionMapping
 * (app/models/integration_collection_mappings/base_collection_mapping.rb) —
 * one per-kind external mapping (fallback item, coupon, subscription fee,
 * minimum commitment, tax, prepaid credit, credit note, account, currencies)
 * for one integration, optionally scoped to a billing entity.
 *
 * Rails stores STI type strings in the frozen `type` column as the Rails
 * class name ("IntegrationCollectionMappings::NetsuiteCollectionMapping");
 * the subclasses re-scope every query with it (Rails STI does this
 * implicitly).
 *
 * TODO(port): Rails' PaperTrailTraceable concern — the `versions` audit
 * trail is not ported.
 */
#[Fillable([
    'integration_id',
    'mapping_type',
    'type',
    'settings',
    'organization_id',
    'billing_entity_id',
])]
#[Table(name: 'integration_collection_mappings')]
class BaseCollectionMapping extends BaseModel
{
    /** Rails: the subclass `type` strings (STI — never renumber/rename). */
    public const NETSUITE_TYPE = 'IntegrationCollectionMappings::NetsuiteCollectionMapping';

    public const ANROK_TYPE = 'IntegrationCollectionMappings::AnrokCollectionMapping';

    public const AVALARA_TYPE = 'IntegrationCollectionMappings::AvalaraCollectionMapping';

    public const XERO_TYPE = 'IntegrationCollectionMappings::XeroCollectionMapping';

    /** Rails: MAPPING_TYPES (enum :mapping_type order — never renumber). */
    public const MAPPING_TYPES = [
        'fallback_item',
        'coupon',
        'subscription_fee',
        'minimum_commitment',
        'tax',
        'prepaid_credit',
        'credit_note',
        'account',
        'currencies',
    ];

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
     * Rails: `enum :mapping_type, MAPPING_TYPES, validate: true` — the
     * wire enum name ↔ stored integer position mapping (0-based).
     */
    public static function mappingTypes(): array
    {
        return array_combine(self::MAPPING_TYPES, array_keys(self::MAPPING_TYPES));
    }

    public static function mappingTypeName(int $position): ?string
    {
        return self::MAPPING_TYPES[$position] ?? null;
    }

    /** Rails: the enum `currencies?` predicate. */
    public function isCurrencies(): bool
    {
        return $this->mapping_type === (self::mappingTypes()['currencies'] ?? -1);
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

    // -- settings_accessors :external_id, :external_account_code,
    //    :external_name (the payloads consume them as plain attributes) -----

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

        // validates :mapping_type, presence: true (+ enum validate: true).
        if ($this->mapping_type === null
            || $this->mappingTypeName((int) $this->mapping_type) === null) {
            $errors['mapping_type'] = ['value_is_mandatory'];
        } else {
            // validates :mapping_type, uniqueness: {scope: [:integration_id,
            // :organization_id, :billing_entity_id]}.
            $scope = static::query()
                ->where('mapping_type', $this->mapping_type)
                ->where('integration_id', $this->integration_id)
                ->where('organization_id', $this->organization_id)
                ->where('billing_entity_id', $this->billing_entity_id);

            if ($this->exists) {
                $scope->where($this->getKeyName(), '!=', $this->getKey());
            }

            if ($scope->exists()) {
                $errors['mapping_type'] = ['value_already_exist'];
            }
        }

        // validate :validate_billing_entity_organization.
        if ($this->billingEntity !== null && $this->billingEntity->organization_id !== $this->organization_id) {
            $errors['billing_entity'] = ['value_is_invalid'];
        }

        return $errors;
    }

    protected function casts(): array
    {
        return [
            'mapping_type' => 'integer',
            'settings' => 'array',
        ];
    }
}
