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
 * Port of Rails' Resolvers::BillableMetricsResolver
 * (app/graphql/resolvers/billable_metrics_resolver.rb): "Query billable
 * metrics of an organization" — the search term and the recurring /
 * aggregation_types / plan_id filters go through the BillableMetricsQuery
 * port, wrapped in the frozen SDL's BillableMetricCollection shape.
 */
class BillableMetrics
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = BillableMetricsQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'recurring' => $args['recurring'] ?? null,
                'aggregation_types' => $args['aggregationTypes'] ?? null,
                'plan_id' => $args['planId'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->billable_metrics);
    }
}
