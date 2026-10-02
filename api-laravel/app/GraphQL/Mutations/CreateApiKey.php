<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\ApiKeys\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ApiKeys::Create
 * (app/graphql/mutations/api_keys/create.rb): "Creates a new API key".
 */
class CreateApiKey
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = CreateService::call(
            params: Args::snakeKeys(Args::input($args)),
            organization: LagoContext::currentOrganization($context),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->api_key;
    }
}
