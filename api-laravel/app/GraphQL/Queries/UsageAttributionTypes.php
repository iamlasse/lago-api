<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\UsageAttributionTypesQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresAccountTree;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::UsageAttributionTypesResolver
 * (app/graphql/resolvers/usage_attribution_types_resolver.rb): "Query usage
 * attribution types of an organization" — the search term, the role / roots
 * filters and the pagination go through the UsageAttributionTypesQuery
 * port, wrapped in the frozen SDL's UsageAttributionTypeCollection shape,
 * gated by the account_tree feature flag.
 */
class UsageAttributionTypes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresAccountTree::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = UsageAttributionTypesQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'role' => $args['role'] ?? null,
                'roots' => $args['roots'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->usage_attribution_types);
    }
}
