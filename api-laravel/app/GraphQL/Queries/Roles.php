<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Role;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::RolesResolver
 * (app/graphql/resolvers/roles_resolver.rb): "Query roles available for the
 * organization" — the predefined roles first (organization_id NULLS FIRST),
 * then the org's own, each group alphabetical by lowercased name, with the
 * active memberships eager-loaded for the wire's `memberships` field.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("roles:view").
 */
class Roles
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): iterable
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        return Role::query()
            ->where(function ($query) use ($organization): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $organization->id);
            })
            ->orderByRaw('organization_id NULLS FIRST')
            ->orderByRaw('LOWER(name)')
            ->get();
    }
}
