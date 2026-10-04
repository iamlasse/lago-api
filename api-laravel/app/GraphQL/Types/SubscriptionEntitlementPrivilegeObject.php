<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Entitlement\SubscriptionEntitlementPrivilege;

/**
 * Field resolvers for the frozen SDL's `SubscriptionEntitlementPrivilegeObject`
 * type (port of Rails' Types::Entitlement::SubscriptionEntitlementPrivilegeObject)
 * — the root is the merged privilege projection; code/name resolve through
 * the fallback.
 */
class SubscriptionEntitlementPrivilegeObject
{
    /** Rails: `object.value_type` — the PG enum label. */
    public function valueType(SubscriptionEntitlementPrivilege $root): string
    {
        return (string) $root->valueType;
    }

    /** Rails: `object.value` — the effective value (subscription or plan). */
    public function value(SubscriptionEntitlementPrivilege $root): ?string
    {
        return $root->value;
    }

    /** Rails: `object.config`. */
    public function config(SubscriptionEntitlementPrivilege $root): array
    {
        $config = $root->config();

        return is_array($config) ? $config : [];
    }
}
