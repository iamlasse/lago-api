<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\ApiKeys\RotateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ApiKeys::Rotate
 * (app/graphql/mutations/api_keys/rotate.rb): "Create new ApiKey while
 * expiring provided".
 */
class RotateApiKey
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.api_keys.find_by(id:)
        $apiKey = $organization->apiKeys()->active()->find($input['id'] ?? null);
        unset($input['id']);

        $result = RotateService::call(
            apiKey: $apiKey,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->api_key;
    }
}
