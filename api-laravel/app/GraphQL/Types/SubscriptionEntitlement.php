<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Entitlement\SubscriptionEntitlement as SubscriptionEntitlementDTO;

/**
 * Field resolvers for the frozen SDL's `SubscriptionEntitlement` type (port
 * of Rails' Types::Entitlement::SubscriptionEntitlementObject, graphql_name
 * "SubscriptionEntitlement") — the root is the merged SubscriptionEntitlement
 * projection.
 */
class SubscriptionEntitlement
{
    /** Rails: `object.privileges` — the merged privilege list. */
    public function privileges(SubscriptionEntitlementDTO $root): array
    {
        return $root->privileges ?? [];
    }
}
