<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\Models\Role as RoleModel;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::RoleResolver
 * (app/graphql/resolvers/role_resolver.rb): "Query a single role" — the
 * organization's roles plus the predefined ones (organization_id NULL).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("roles:view").
 */
class Role
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): RoleModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: Role.with_organization(current_organization.id).find(id)
        // — with_organization matches organization_id: [nil, org.id].
        $role = RoleModel::query()
            ->where(function ($query) use ($organization): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $organization->id);
            })
            ->find($args['id'] ?? null);

        // Rails: rescue ActiveRecord::RecordNotFound → not_found_error.
        if ($role === null) {
            throw Errors::notFoundError('role');
        }

        return $role;
    }
}
