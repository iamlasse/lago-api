<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\AnrokIntegration as AnrokIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `AnrokIntegration` type (port of
 * Rails' Types::Integrations::Anrok) — the secrets accessors obfuscated,
 * the external account id parsed out of the api key, and the organization's
 * failed tax invoice count.
 */
class AnrokIntegration
{
    /** Rails: field :api_key, ObfuscatedStringType. */
    public function apiKey(AnrokIntegrationModel $root): string
    {
        return (string) $root->getFromSecrets('api_key');
    }

    /**
     * Rails: `external_account_id` — the Anrok account id is the part of
     * the api key before the first "/".
     */
    public function externalAccountId(AnrokIntegrationModel $root): ?string
    {
        $apiKey = (string) $root->getFromSecrets('api_key');

        if (! str_contains($apiKey, '/')) {
            return null;
        }

        return explode('/', $apiKey)[0];
    }

    /**
     * Rails: `failed_invoices_count` — the organization's failed invoices
     * joined to tax_error details.
     *
     * TODO(port): the ErrorDetails model is not ported yet, so the count is
     * 0 until that lands.
     */
    public function failedInvoicesCount(AnrokIntegrationModel $root): int
    {
        return 0;
    }

    /**
     * Rails: `has_mappings_configured` — any
     * IntegrationCollectionMappings::AnrokCollectionMapping on the
     * integration.
     *
     * TODO(port): the collection mappings slice; no mappings can be
     * configured yet, so always false.
     */
    public function hasMappingsConfigured(AnrokIntegrationModel $root): bool
    {
        return false;
    }
}
