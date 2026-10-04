<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Queries\OrdersQuery;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::OrdersResolver
 * (app/graphql/resolvers/orders_resolver.rb): "Query orders of an
 * organization" — the filters and the search term go through the
 * OrdersQuery port, wrapped in the frozen SDL's OrderCollection shape
 * (`collection` + `metadata`). No feature gate on the GraphQL surface
 * (unlike the REST controller).
 */
class Orders
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = OrdersQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'status' => $args['status'] ?? null,
                'order_type' => $args['orderType'] ?? null,
                'execution_mode' => $args['executionMode'] ?? null,
                'customer_id' => $args['customerId'] ?? null,
                'number' => $args['number'] ?? null,
                'order_form_number' => $args['orderFormNumber'] ?? null,
                'quote_number' => $args['quoteNumber'] ?? null,
                'owner_id' => $args['ownerId'] ?? null,
                'executed_at_from' => $args['executedAtFrom'] ?? null,
                'executed_at_to' => $args['executedAtTo'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->orders);
    }
}
