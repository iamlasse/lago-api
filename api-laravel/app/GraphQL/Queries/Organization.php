<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::OrganizationResolver
 * (app/graphql/resolvers/organization_resolver.rb): "Query the current
 * organization" — the organization behind the x-lago-organization switch,
 * guarded like the Rails resolver's AuthenticableApiUser +
 * RequiredOrganization concerns.
 */
class Organization
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        return LagoContext::currentOrganization($context);
    }
}
