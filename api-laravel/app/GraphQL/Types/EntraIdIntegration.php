<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\EntraIdIntegration as EntraIdIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `EntraIdIntegration` type (port of
 * Rails' Types::Integrations::EntraId) — the settings/secrets accessors
 * with the client secret obfuscated.
 */
class EntraIdIntegration
{
    /** Rails: field :client_id (the settings accessor). */
    public function clientId(EntraIdIntegrationModel $root): ?string
    {
        return $root->clientId();
    }

    /** Rails: field :client_secret, ObfuscatedStringType. */
    public function clientSecret(EntraIdIntegrationModel $root): string
    {
        return (string) $root->clientSecret();
    }

    /** Rails: field :domain (the settings accessor). */
    public function domain(EntraIdIntegrationModel $root): string
    {
        return (string) $root->domain();
    }

    /** Rails: field :host — the settings value or login.microsoftonline.com. */
    public function host(EntraIdIntegrationModel $root): ?string
    {
        return $root->host();
    }

    /** Rails: field :tenant_id (the settings accessor). */
    public function tenantId(EntraIdIntegrationModel $root): string
    {
        return (string) $root->tenantId();
    }
}
