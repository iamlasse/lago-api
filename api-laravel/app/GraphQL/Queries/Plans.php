<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Queries\PlansQuery;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PlansResolver
 * (app/graphql/resolvers/plans_resolver.rb): "Query plans of an organization"
 * — the free-text search term and the with_deleted filter go through the
 * PlansQuery port, wrapped in the frozen SDL's PlanCollection shape
 * (`collection` + `metadata`).
 *
 * Rails' REQUIRED_PERMISSION = "plans:view"
 * (CanRequirePermissions) is not enforced yet — the roles/Permission slice
 * does not populate context permissions (same as the other query ports,
 * e.g. Queries\AddOns).
 */
class Plans
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = PlansQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'with_deleted' => $args['withDeleted'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->plans);
    }
}
