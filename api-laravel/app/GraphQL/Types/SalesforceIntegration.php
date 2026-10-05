<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Integrations\SalesforceIntegration as SalesforceIntegrationModel;

/**
 * Field resolvers for the frozen SDL's `SalesforceIntegration` type (port of
 * Rails' Types::Integrations::Salesforce) — the instance id from settings.
 */
class SalesforceIntegration
{
    /** Rails: field :instance_id (settings accessor). */
    public function instanceId(SalesforceIntegrationModel $root): string
    {
        return (string) $root->getFromSettings('instance_id');
    }
}
