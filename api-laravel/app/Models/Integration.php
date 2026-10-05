<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Port of Rails' Integrations::BaseIntegration (app/models/integrations/
 * base_integration.rb) — minimal, covering the SSO domain lookups. The
 * integrations create/update/destroy surface and the accounting/tax
 * integrations belong to their own slices.
 *
 * Rails stores STI type strings in the frozen `type` column as the Rails
 * class name ("Integrations::OktaIntegration"); the subclasses re-scope every
 * query with it (Rails STI does this implicitly).
 *
 * TODO(port): Rails `encrypts :secrets` — the port reads/writes the secrets
 * column as plain JSON (the frozen column is varchar) until the Rails
 * ciphertext migration path exists.
 */
#[Fillable([
    'organization_id',
    'name',
    'code',
    'type',
    'secrets',
    'settings',
])]
#[Table(name: 'integrations')]
class Integration extends BaseModel
{
    public const OKTA_TYPE = 'Integrations::OktaIntegration';

    public const ENTRA_ID_TYPE = 'Integrations::EntraIdIntegration';

    public const ANROK_TYPE = 'Integrations::AnrokIntegration';

    public const AVALARA_TYPE = 'Integrations::AvalaraIntegration';

    /** Rails: INTEGRATION_TAX_TYPES (app/models/integrations/base_integration.rb). */
    public const INTEGRATION_TAX_TYPES = [self::ANROK_TYPE, self::AVALARA_TYPE];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rails: has_many :integration_customers, class_name: "IntegrationCustomers::BaseCustomer". */
    public function integrationCustomers(): HasMany
    {
        return $this->hasMany(IntegrationCustomer::class);
    }

    /**
     * Rails: `provider_key` — the short provider key, e.g. "anrok",
     * "avalara" (type.demodulize.delete_suffix("Integration").underscore).
     */
    public function providerKey(): string
    {
        return Str::snake(preg_replace('/Integration$/', '', Str::afterLast((string) $this->type, '::')));
    }

    // -- SettingsStorable / SecretsStorable (app/models/concerns/
    //   settings_storable.rb, secrets_storable.rb) ----------------------------

    public function getFromSettings(string $key): mixed
    {
        return data_get($this->settings, $key);
    }

    /** Rails: `get_from_secrets(key)` over the JSON-encoded secrets column. */
    public function getFromSecrets(string $key): mixed
    {
        $decoded = json_decode((string) ($this->secrets ?? '{}'), true);

        return data_get($decoded, $key);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
