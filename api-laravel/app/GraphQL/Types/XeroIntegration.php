<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\XeroIntegration as XeroIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `XeroIntegration` type (port of
 * Rails' Types::Integrations::Xero) — the connection id out of the secrets
 * and the sync flags out of the settings.
 */
class XeroIntegration
{
    /** Rails: field :connection_id, ID (the secrets accessor). */
    public function connectionId(XeroIntegrationModel $root): ?string
    {
        return $root->getFromSecrets('connection_id');
    }

    /** Rails: field :sync_credit_notes. */
    public function syncCreditNotes(XeroIntegrationModel $root): ?bool
    {
        return $root->getFromSettings('sync_credit_notes');
    }

    /** Rails: field :sync_invoices. */
    public function syncInvoices(XeroIntegrationModel $root): ?bool
    {
        return $root->getFromSettings('sync_invoices');
    }

    /** Rails: field :sync_payments. */
    public function syncPayments(XeroIntegrationModel $root): ?bool
    {
        return $root->getFromSettings('sync_payments');
    }

    /**
     * Rails: `has_mappings_configured` — any
     * IntegrationCollectionMappings::XeroCollectionMapping on the
     * integration.
     *
     * TODO(port): the collection mappings slice; no mappings can be
     * configured yet, so always false.
     */
    public function hasMappingsConfigured(XeroIntegrationModel $root): bool
    {
        return false;
    }
}
