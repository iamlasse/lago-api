<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
