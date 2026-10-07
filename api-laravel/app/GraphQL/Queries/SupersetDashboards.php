<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Auth\SupersetService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Superset::DashboardsResolver
 * (app/graphql/resolvers/superset/dashboards_resolver.rb): "Query all
 * Superset dashboards with embedded configuration and guest tokens".
 *
 * TODO(port): Rails also gates the resolver behind the `analytics:view`
 * permission (REQUIRED_PERMISSION); the permission port is pending (see
 * graphql/FULL_SCHEMA_NOTES.md item 3).
 */
class SupersetDashboards
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = SupersetService::call(
            organization: LagoContext::currentOrganization($context),
            user: [],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->dashboards;
    }
}
