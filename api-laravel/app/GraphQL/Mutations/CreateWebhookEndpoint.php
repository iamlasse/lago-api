<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Webhooks\Endpoints\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::WebhookEndpoints::Create
 * (app/graphql/mutations/webhook_endpoints/create.rb): "Create a new webhook
 * endpoint".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("developers:manage").
 */
class CreateWebhookEndpoint
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = CreateService::call(
            organization: LagoContext::currentOrganization($context),
            params: \App\GraphQL\Support\Args::snakeKeys(\App\GraphQL\Support\Args::input($args)),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->webhook_endpoint;
    }
}
