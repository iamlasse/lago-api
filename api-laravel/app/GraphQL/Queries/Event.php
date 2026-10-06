<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Queries\EventQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::EventResolver
 * (app/graphql/resolvers/event_resolver.rb): "Query a single event of an
 * organization" — keyed by transaction id; the millisecond timestamp filter
 * only bites on the ClickHouse store (TODO(port)).
 */
class Event
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $queryResult = EventQuery::call(
            organization: LagoContext::currentOrganization($context),
            filters: [
                'transaction_id' => $args['transactionId'] ?? null,
                'external_subscription_id' => $args['externalSubscriptionId'] ?? null,
                'code' => $args['code'] ?? null,
            ],
        );

        if ($queryResult->failure()) {
            throw Errors::resultError($queryResult->getError());
        }

        // Rails: query_result.event || not_found_error(resource: "event").
        return $queryResult->event ?? throw Errors::notFoundError('event');
    }
}
