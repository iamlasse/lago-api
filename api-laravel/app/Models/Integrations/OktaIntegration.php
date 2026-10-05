<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Models\Integration;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\OktaIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;

/**
 * Port of Rails' Integrations::OktaIntegration (app/models/integrations/
 * okta_integration.rb) — the settings/secrets accessors and the host default
 * the auth services read. The create/update validations (domain uniqueness,
 * presence) live with the integrations REST slice.
 */
#[UseFactory(OktaIntegrationFactory::class)]
class OktaIntegration extends Integration
{
    use HasFactory;

    /** Rails: settings_accessors :client_id, :domain, :organization_name, :host. */
    public function clientId(): ?string
    {
        return $this->getFromSettings('client_id');
    }

    public function domain(): ?string
    {
        return $this->getFromSettings('domain');
    }

    public function organizationName(): ?string
    {
        return $this->getFromSettings('organization_name');
    }

    /** Rails: `host` — settings value, or "<organization_name>.okta.com". */
    public function host(): ?string
    {
        $host = $this->getFromSettings('host');

        return $host !== null && $host !== ''
            ? $host
            : Str::lower((string) $this->organizationName()).'.okta.com';
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
            $builder->where('type', self::OKTA_TYPE);
        });
    }
}
