<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\Queries\PricingUnitsQuery;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PricingUnitsResolver
 * (app/graphql/resolvers/pricing_units_resolver.rb): "Query the pricing units
 * of current organization" — the free-text search term goes through the
 * PricingUnitsQuery port, wrapped in the frozen SDL's PricingUnitCollection
 * shape (`collection` + `metadata`).
 *
 * Rails' REQUIRED_PERMISSION = "pricing_units:view"
 * (CanRequirePermissions) is not enforced yet — the roles/Permission slice
 * does not populate context permissions (same as the other query ports,
 * e.g. Queries\AddOns).
 */
class PricingUnits
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = PricingUnitsQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->pricing_units);
    }
}
