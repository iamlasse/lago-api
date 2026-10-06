<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `CustomerPortalOrganization` type
 * (port of Rails' Types::CustomerPortal::Organizations::Object, which
 * subclasses Types::Organizations::BaseOrganizationType): the trimmed
 * organization exposed through the customer portal. The billing
 * configuration / logo / timezone resolvers come from the organization
 * type; default_currency and name resolve as plain columns.
 */
class CustomerPortalOrganization extends Organization
{
    /** Rails: premium_integrations column (validated against the enum). */
    public function premiumIntegrations(mixed $root): array
    {
        return array_values((array) ($root->premium_integrations ?? []));
    }
}
