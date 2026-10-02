<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\ApiKeys\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ApiKeys::Destroy
 * (app/graphql/mutations/api_keys/destroy.rb): "Deletes an API key" —
 * expires the key (soft delete).
 */
class DestroyApiKey
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.api_keys.find_by(id:)
        $apiKey = $organization->apiKeys()->active()->find($args['input']['id'] ?? null);

        $result = DestroyService::call(apiKey: $apiKey);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->api_key;
    }
}
