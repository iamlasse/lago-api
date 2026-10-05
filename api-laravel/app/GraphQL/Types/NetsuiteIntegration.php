<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\NetsuiteIntegration as NetsuiteIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `NetsuiteIntegration` type (port of
 * Rails' Types::Integrations::Netsuite) — the TBA credentials split between
 * the settings and the (obfuscated) secrets.
 */
class NetsuiteIntegration
{
    /** Rails: field :account_id. */
    public function accountId(NetsuiteIntegrationModel $root): ?string
    {
        return $root->getFromSettings('account_id');
    }

    /** Rails: field :client_id. */
    public function clientId(NetsuiteIntegrationModel $root): ?string
    {
        return $root->getFromSettings('client_id');
    }

    /** Rails: field :client_secret, ObfuscatedString (the secrets accessor). */
    public function clientSecret(NetsuiteIntegrationModel $root): string
    {
        return (string) $root->getFromSecrets('client_secret');
    }

    /** Rails: field :connection_id, ID (the secrets accessor). */
    public function connectionId(NetsuiteIntegrationModel $root): ?string
    {
        return $root->getFromSecrets('connection_id');
    }

    /** Rails: field :script_endpoint_url. */
    public function scriptEndpointUrl(NetsuiteIntegrationModel $root): ?string
    {
        return $root->getFromSettings('script_endpoint_url');
    }

    /** Rails: field :sync_credit_notes. */
    public function syncCreditNotes(NetsuiteIntegrationModel $root): ?bool
    {
        return $root->getFromSettings('sync_credit_notes');
    }

    /** Rails: field :sync_invoices. */
    public function syncInvoices(NetsuiteIntegrationModel $root): ?bool
    {
        return $root->getFromSettings('sync_invoices');
    }

    /** Rails: field :sync_payments. */
    public function syncPayments(NetsuiteIntegrationModel $root): ?bool
    {
        return $root->getFromSettings('sync_payments');
    }

    /** Rails: field :token_id. */
    public function tokenId(NetsuiteIntegrationModel $root): ?string
    {
        return $root->getFromSettings('token_id');
    }

    /** Rails: field :token_secret, ObfuscatedString (the secrets accessor). */
    public function tokenSecret(NetsuiteIntegrationModel $root): string
    {
        return (string) $root->getFromSecrets('token_secret');
    }

    /**
     * Rails: `has_mappings_configured` — any
     * IntegrationCollectionMappings::NetsuiteCollectionMapping on the
     * integration.
     *
     * TODO(port): the collection mappings slice; no mappings can be
     * configured yet, so always false.
     */
    public function hasMappingsConfigured(NetsuiteIntegrationModel $root): bool
    {
        return false;
    }
}
