<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\AvalaraIntegration as AvalaraIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `AvalaraIntegration` type (port of
 * Rails' Types::Integrations::Avalara) — company_code from settings (the
 * snake_case attribute fallback reads it too, but the secrets license key
 * and the computed fields need explicit resolvers).
 */
class AvalaraIntegration
{
    /** Rails: field :account_id (settings accessor). */
    public function accountId(AvalaraIntegrationModel $root): ?string
    {
        return $root->getFromSettings('account_id');
    }

    /** Rails: field :company_code (settings accessor). */
    public function companyCode(AvalaraIntegrationModel $root): string
    {
        return (string) $root->getFromSettings('company_code');
    }

    /** Rails: field :company_id — set by FetchCompanyIdService after creation. */
    public function companyId(AvalaraIntegrationModel $root): ?string
    {
        return $root->getFromSettings('company_id');
    }

    /** Rails: field :license_key, ObfuscatedStringType (secrets accessor). */
    public function licenseKey(AvalaraIntegrationModel $root): string
    {
        return (string) $root->getFromSecrets('license_key');
    }

    /**
     * Rails: `failed_invoices_count` — the organization's failed invoices
     * joined to tax_error details.
     *
     * TODO(port): the ErrorDetails model is not ported yet, so the count is
     * 0 until that lands.
     */
    public function failedInvoicesCount(AvalaraIntegrationModel $root): int
    {
        return 0;
    }

    /**
     * Rails: `has_mappings_configured` — any
     * IntegrationCollectionMappings::AvalaraCollectionMapping on the
     * integration.
     *
     * TODO(port): the collection mappings slice; no mappings can be
     * configured yet, so always false.
     */
    public function hasMappingsConfigured(AvalaraIntegrationModel $root): bool
    {
        return false;
    }
}
