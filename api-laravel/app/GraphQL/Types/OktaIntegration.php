<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\OktaIntegration as OktaIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `OktaIntegration` type (port of
 * Rails' Types::Integrations::Okta) — the settings/secrets accessors with
 * the client secret obfuscated.
 */
class OktaIntegration
{
    /** Rails: field :client_id (the settings accessor). */
    public function clientId(OktaIntegrationModel $root): ?string
    {
        return $root->clientId();
    }

    /** Rails: field :client_secret, ObfuscatedStringType. */
    public function clientSecret(OktaIntegrationModel $root): string
    {
        return (string) $root->clientSecret();
    }

    /** Rails: field :domain (the settings accessor). */
    public function domain(OktaIntegrationModel $root): string
    {
        return (string) $root->domain();
    }

    /** Rails: field :host — the settings value or "<org>.okta.com". */
    public function host(OktaIntegrationModel $root): ?string
    {
        return $root->host();
    }

    /** Rails: field :organization_name (the settings accessor). */
    public function organizationName(OktaIntegrationModel $root): string
    {
        return (string) $root->organizationName();
    }
}
