<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Organization;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\ApiKey as ApiKeyModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ApiKeyResolver
 * (app/graphql/resolvers/api_key_resolver.rb): "Query the API key" — scoped
 * to the current organization, RecordNotFound becomes the not_found error.
 */
class ApiKey
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ApiKeyModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $apiKey = $organization->apiKeys()->active()->find($args['id'] ?? null);

        if ($apiKey === null) {
            throw Errors::notFoundError('api_key');
        }

        return $apiKey;
    }
}
