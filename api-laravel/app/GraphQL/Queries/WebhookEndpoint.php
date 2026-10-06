<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\WebhookEndpoint as WebhookEndpointModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WebhookEndpointResolver
 * (app/graphql/resolvers/webhook_endpoint_resolver.rb): "Query a single
 * webhook endpoint".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 */
class WebhookEndpoint
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): WebhookEndpointModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $endpoint = WebhookEndpointModel::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $args['id'] ?? null)
            ->first();

        if ($endpoint === null) {
            throw Errors::notFoundError('webhook_endpoint');
        }

        return $endpoint;
    }
}
