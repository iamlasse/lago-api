<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\BillableMetricsQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::SelectableBillableMetricsResolver
 * (app/graphql/resolvers/selectable_billable_metrics_resolver.rb): "Query
 * billable metrics of an organization for selection inputs" — the same
 * BillableMetricsQuery read, no extra filters.
 *
 * TODO(port): the REQUIRED_PERMISSION gates
 * (%w[coupons:view coupons:update wallets:create wallets:update]).
 */
class SelectableBillableMetrics
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = BillableMetricsQuery::call(
            organization: LagoContext::currentOrganization($context),
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->billable_metrics);
    }
}
