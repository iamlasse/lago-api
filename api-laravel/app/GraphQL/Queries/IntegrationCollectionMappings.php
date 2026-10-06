<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Queries\IntegrationCollectionMappingsQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::IntegrationCollectionMappingsResolver
 * (app/graphql/resolvers/integration_collection_mappings_resolver.rb):
 * "Query integration collection mappings".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:view").
 */
class IntegrationCollectionMappings
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = IntegrationCollectionMappingsQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'integration_id' => $args['integrationId'],
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->integration_collection_mappings);
    }
}
