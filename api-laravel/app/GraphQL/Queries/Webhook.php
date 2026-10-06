<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Webhook as WebhookModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WebhookResolver
 * (app/graphql/resolvers/webhook_resolver.rb): "Query a webhook".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 */
class Webhook
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): WebhookModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $webhook = WebhookModel::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $args['id'] ?? null)
            ->first();

        if ($webhook === null) {
            throw Errors::notFoundError('webhook');
        }

        return $webhook;
    }
}
