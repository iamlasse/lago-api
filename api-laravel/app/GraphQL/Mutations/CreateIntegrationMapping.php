<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\IntegrationMappings\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::IntegrationMappings::Create (app/graphql/
 * mutations/integration_mappings/create.rb): "Create integration mapping".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:update").
 */
class CreateIntegrationMapping
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = CreateService::call(
            args: Args::snakeKeys(Args::input($args)),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->integration_mapping;
    }
}
