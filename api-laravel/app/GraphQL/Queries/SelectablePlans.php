<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Queries\PlansQuery;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::SelectablePlansResolver
 * (app/graphql/resolvers/selectable_plans_resolver.rb): "Query plans of an
 * organization for selection inputs" — the same PlansQuery read, no extra
 * filters.
 *
 * TODO(port): the REQUIRED_PERMISSION gates
 * (%w[coupons:view coupons:update wallets:create wallets:update]).
 */
class SelectablePlans
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = PlansQuery::call(
            organization: LagoContext::currentOrganization($context),
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->plans);
    }
}
