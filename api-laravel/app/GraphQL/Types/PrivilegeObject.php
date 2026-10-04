<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Privilege;

/**
 * Field resolvers for the frozen SDL's `PrivilegeObject` type (port of
 * Rails' Types::Entitlement::PrivilegeObject). code/id/name resolve through
 * the fallback; config and valueType need methods.
 */
class PrivilegeObject
{
    /** Rails: `object.value_type` — the PG enum label. */
    public function valueType(Privilege $root): string
    {
        return (string) $root->value_type;
    }

    /** Rails: `object.config` — the jsonb config hash. */
    public function config(Privilege $root): array
    {
        return is_array($root->config) ? $root->config : [];
    }
}
