<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

/**
 * Field resolvers for the frozen SDL's `PrivilegeConfigObject` type (port
 * of Rails' Types::Entitlement::PrivilegeConfigObject) — the root is the
 * privilege's config hash.
 */
class PrivilegeConfigObject
{
    /**
     * Rails: `object.select_options` — the available options for select
     * value_type privileges.
     *
     * @return list<string>|null
     */
    public function selectOptions(array $root): ?array
    {
        $options = $root['select_options'] ?? null;

        return is_array($options) ? array_values($options) : null;
    }
}
