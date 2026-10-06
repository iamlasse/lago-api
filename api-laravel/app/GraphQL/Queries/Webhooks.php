<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\Queries\WebhooksQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WebhooksResolver
 * (app/graphql/resolvers/webhooks_resolver.rb): "Query Webhooks" — the
 * webhook log of one endpoint (webhookEndpointId is mandatory), with the
 * statuses/eventTypes/httpStatuses/date-range filters.
 *
 * Rails keeps the deprecated singular `status` argument: a lone status is
 * promoted to a one-element statuses list.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 */
class Webhooks
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $statuses = isset($args['statuses']) ? array_values((array) $args['statuses']) : null;

        // TODO: remove :status after migrating to :statuses.
        if (($statuses === null || $statuses === []) && ($args['status'] ?? null) !== null) {
            $statuses = [$args['status']];
        }

        $result = WebhooksQuery::call(
            organization: LagoContext::currentOrganization($context),
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'webhook_endpoint_id' => $args['webhookEndpointId'],
                'statuses' => $statuses,
                'event_types' => isset($args['eventTypes']) ? array_values((array) $args['eventTypes']) : null,
                'http_statuses' => isset($args['httpStatuses']) ? array_values((array) $args['httpStatuses']) : null,
                'from_date' => $args['fromDate'] ?? null,
                'to_date' => $args['toDate'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->webhooks);
    }
}
