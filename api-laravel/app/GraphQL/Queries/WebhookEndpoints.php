<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\WebhookEndpointsQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WebhookEndpointsResolver
 * (app/graphql/resolvers/webhook_endpoints_resolver.rb): "Query webhook
 * endpoints of an organization".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 * TODO(port): the searchTerm filter — WebhookEndpointsQuery carries a
 * documented TODO for the ransack webhook_url search (REST path shares it).
 */
class WebhookEndpoints
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = WebhookEndpointsQuery::call(
            organization: LagoContext::currentOrganization($context),
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->webhook_endpoints);
    }
}
