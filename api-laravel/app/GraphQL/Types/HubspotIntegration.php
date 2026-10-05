<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\HubspotIntegration as HubspotIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `HubspotIntegration` type (port of
 * Rails' Types::Integrations::Hubspot) — the connection secret, the
 * settings accessors and the hardcoded object type ids.
 */
class HubspotIntegration
{
    /** Rails: field :connection_id, ID (secrets accessor). */
    public function connectionId(HubspotIntegrationModel $root): string
    {
        return (string) $root->getFromSecrets('connection_id');
    }

    /** Rails: field :default_targeted_object, HubspotTargetedObjectsEnum. */
    public function defaultTargetedObject(HubspotIntegrationModel $root): string
    {
        return (string) $root->defaultTargetedObject();
    }

    /** Rails: field :invoices_object_type_id (settings accessor). */
    public function invoicesObjectTypeId(HubspotIntegrationModel $root): ?string
    {
        return $root->invoicesObjectTypeId();
    }

    /** Rails: field :portal_id (settings accessor). */
    public function portalId(HubspotIntegrationModel $root): ?string
    {
        return $root->portalId();
    }

    /** Rails: field :subscriptions_object_type_id (settings accessor). */
    public function subscriptionsObjectTypeId(HubspotIntegrationModel $root): ?string
    {
        return $root->subscriptionsObjectTypeId();
    }

    /** Rails: field :sync_invoices (settings accessor). */
    public function syncInvoices(HubspotIntegrationModel $root): ?bool
    {
        return $root->syncInvoices();
    }

    /** Rails: field :sync_subscriptions (settings accessor). */
    public function syncSubscriptions(HubspotIntegrationModel $root): ?bool
    {
        return $root->syncSubscriptions();
    }
}
