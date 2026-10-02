<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Subscriptions\Query as SubscriptionsQuery;

/**
 * Port of Rails' Resolvers::SubscriptionsResolver
 * (app/graphql/resolvers/subscriptions_resolver.rb): "Query subscriptions of
 * an organization" — filters, search term, kaminari pagination, wrapped in
 * the frozen SDL's SubscriptionCollection shape (`collection` + `metadata`).
 *
 * Rails always passes `exclude_next_subscriptions: true` (the FE pulls
 * next_subscription through the object type, so a listed subscription's
 * successor must not appear again in the list). The `status` list stays in
 * the filters — the query validates it Rails-style (`valid_status?`) and
 * skips the filter when any status is unknown.
 */
class Subscriptions
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $filters = Args::snakeKeys($args);
        unset($filters['page'], $filters['limit'], $filters['searchTerm']);

        // Rails: exclude_next_subscriptions: true — the FE pulls
        // next_subscription through the object type, so a listed
        // subscription's successor must not appear again.
        $filters['exclude_next_subscriptions'] = true;

        $result = SubscriptionsQuery::call(
            organization: $organization,
            filters: $filters,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->subscriptions);
    }
}
