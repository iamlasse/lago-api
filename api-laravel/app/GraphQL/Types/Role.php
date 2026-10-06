<?php

declare(strict_types=1);

namespace App\GraphQL\Types;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Role as RoleModel;
use App\GraphQL\Support\LagoContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Field resolvers for the frozen SDL's `Role` type (port of Rails'
 * Types::RoleType).
 */
class Role
{
    /**
     * Rails: object.permissions_hash.filter_map { |k, v| k if v } — the
     * granted permission keys.
     *
     * @return list<string>
     */
    public function permissions(RoleModel $root): array
    {
        // Rails serializes the "addons:view" keys through PermissionEnum,
        // whose wire values are the underscored forms.
        return array_map(
            fn (string $permission): string => str_replace(':', '_', $permission),
            array_keys(array_filter(
                $root->permissionsHash(),
                fn (bool $granted): bool => $granted,
            )),
        );
    }

    /**
     * Rails: dataloader over Sources::MembershipsForRole — the current
     * organization's active memberships carrying this role.
     *
     * @return iterable<Membership>
     */
    public function memberships(RoleModel $root, array $args, GraphQLContext $context): iterable
    {
        $organization = LagoContext::currentOrganization($context);

        return Membership::query()
            ->where('memberships.status', 0)

            ->when($organization !== null, fn ($query) => $query->where('memberships.organization_id', $organization->id))
            ->whereIn('memberships.id', MembershipRole::query()
                ->whereNull('membership_roles.deleted_at')
                ->where('membership_roles.role_id', $root->id)
                ->select('membership_roles.membership_id'))
            ->get();
    }
}
