<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\EntraIdIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::EntraIdIntegration (app/models/integrations/
 * entra_id_integration.rb) — the settings/secrets accessors and the host
 * default the auth services read. The create/update validations (domain
 * uniqueness, tenant_id/host URL-segment format) live with the integrations
 * REST slice.
 */
#[UseFactory(EntraIdIntegrationFactory::class)]
class EntraIdIntegration extends Integration
{
    use HasFactory;

    /** Rails: settings_accessors :client_id, :domain, :tenant_id, :host. */
    public function clientId(): ?string
    {
        return $this->getFromSettings('client_id');
    }

    public function domain(): ?string
    {
        return $this->getFromSettings('domain');
    }

    public function tenantId(): ?string
    {
        return $this->getFromSettings('tenant_id');
    }

    /** Rails: `host` — settings value (or default login.microsoftonline.com). */
    public function host(): ?string
    {
        $host = $this->getFromSettings('host');

        return ($host ?? '') !== '' ? $host : 'login.microsoftonline.com';
    }

    /** Rails: secrets_accessors :client_secret. */
    public function clientSecret(): ?string
    {
        return $this->getFromSecrets('client_secret');
    }

    protected static function booted(): void
    {
        // Rails STI: querying the subclass filters on the stored type string.
        static::addGlobalScope('stiType', function (Builder $builder): void {
            $builder->where('type', self::ENTRA_ID_TYPE);
        });
    }
}
