<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Queries\QuotesQuery;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::QuotesResolver (app/graphql/resolvers/
 * quotes_resolver.rb): "Query quotes of an organization" — the filters go
 * through the QuotesQuery port, wrapped in the frozen SDL's QuoteCollection
 * shape (`collection` + `metadata`).
 */
class Quotes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = QuotesQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'customers' => $args['customers'] ?? null,
                'statuses' => $args['statuses'] ?? null,
                'numbers' => $args['numbers'] ?? null,
                'from_date' => $args['fromDate'] ?? null,
                'to_date' => $args['toDate'] ?? null,
                'owners' => $args['owners'] ?? null,
                'order_types' => $args['orderTypes'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->quotes);
    }
}
