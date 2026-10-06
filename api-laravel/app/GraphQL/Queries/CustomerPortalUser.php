<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\LagoContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::CustomerResolver
 * (app/graphql/resolvers/customer_portal/customer_resolver.rb): "Query a
 * customer portal user" — the token-authenticated customer itself.
 */
class CustomerPortalUser
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        \App\GraphQL\Guards\CustomerPortalUser::authorize($context);

        return LagoContext::customerPortalUser($context);
    }
}
