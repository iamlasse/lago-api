<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Integration;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Integrations\Aggregator\SubsidiariesService;

/**
 * Port of Rails' Resolvers::Integrations::SubsidiariesResolver
 * (app/graphql/resolvers/integrations/subsidiaries_resolver.rb): "Query
 * integration subsidiaries" — the Nango subsidiaries pull.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("organization:integrations:view").
 */
class IntegrationSubsidiaries
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        // Rails: current_organization.integrations.find(integration_id) —
        // a missing id raises the not_found envelope.
        $integration = Integration::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->find($args['integrationId'] ?? null);

        if ($integration === null) {
            throw Errors::notFoundError('integration');
        }

        $result = SubsidiariesService::call(integration: $integration);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails returns the raw Array to graphql-pagination's collection_type.
        $subsidiaries = $result->subsidiaries;
        $count = is_countable($subsidiaries) ? count($subsidiaries) : 0;

        return (object) [
            'collection' => $subsidiaries,
            'metadata' => (object) [
                'currentPage' => 1,
                'limitValue' => $count,
                'totalPages' => 1,
                'totalCount' => $count,
            ],
        ];
    }
}
