<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Integrations\Okta\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Integrations::Okta::Create
 * (app/graphql/mutations/integrations/okta/create.rb): "Create Okta
 * integration".
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:create").
 */
class CreateOktaIntegration
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = \App\GraphQL\Support\Args::snakeKeys(\App\GraphQL\Support\Args::input($args));

        $result = CreateService::call(
            user: LagoContext::currentUser($context),
            organization_id: LagoContext::currentOrganization($context)->id,
            client_id: $input['client_id'] ?? null,
            client_secret: $input['client_secret'] ?? null,
            domain: $input['domain'] ?? null,
            organization_name: $input['organization_name'] ?? null,
            host: $input['host'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration;
    }
}
