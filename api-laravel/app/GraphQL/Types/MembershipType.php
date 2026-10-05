<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Membership;
use Illuminate\Support\Str;

/**
 * Field resolvers for the partial `Membership` type (port of Rails'
 * app/graphql/types/membership_type.rb).
 */
class MembershipType
{
    /**
     * Rails serializes the integer status enum as its Rails name
     * (`active` / `revoked`) — the frozen schema's MembershipStatus values.
     */
    public function status(Membership $root): string
    {
        return $root->status->label();
    }

    /** Rails: `object.roles.pluck(:name)` — has_many :roles, through: :membership_roles. */
    public function roles(Membership $root): array
    {
        return $root->roles()->pluck('roles.name')->all();
    }

    /**
     * Rails: `object.permissions_hash.transform_keys { |key| key.tr(":", "_") }`
     * — GraphQL field names cannot carry colons, so "addons:view" becomes the
     * frozen SDL's `addonsView` boolean. Lighthouse's array resolver looks up
     * the field name verbatim, so the keys are camelCased.
     *
     * @return array<string, bool>
     */
    public function permissions(Membership $root): array
    {
        $hash = [];

        foreach ($root->permissionsHash() as $permission => $granted) {
            $studly = implode('', array_map(Str::studly(...), explode(':', $permission)));
            $hash[lcfirst($studly)] = $granted;
        }

        return $hash;
    }
}
