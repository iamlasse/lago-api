<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Integration;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\IntegrationItemsQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::IntegrationItemsResolver
 * (app/graphql/resolvers/integration_items_resolver.rb): "Query integration
 * items of an integration".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:view").
 */
class IntegrationItems
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.integrations.where(id:).first — a
        // missing integration answers the not_found envelope.
        $integration = Integration::query()
            ->where('organization_id', $organization->id)
            ->where('id', $args['integrationId'] ?? null)
            ->first();

        if ($integration === null) {
            throw Errors::notFoundError('integration');
        }

        $result = IntegrationItemsQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'integration_id' => $args['integrationId'],
                'item_type' => $args['itemType'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->integration_items);
    }
}
