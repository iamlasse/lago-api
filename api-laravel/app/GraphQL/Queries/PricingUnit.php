<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\PricingUnit as PricingUnitModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PricingUnitResolver
 * (app/graphql/resolvers/pricing_unit_resolver.rb): "Query the pricing unit"
 * — current_organization.pricing_units.find(id); an unknown id answers with
 * the not_found envelope.
 *
 * Rails' REQUIRED_PERMISSION = "pricing_units:view"
 * (CanRequirePermissions) is not enforced yet — the roles/Permission slice
 * does not populate context permissions (same as the other query ports,
 * e.g. Queries\AddOn).
 */
class PricingUnit
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): PricingUnitModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = PricingUnitModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('pricing_unit');
        }

        return $found;
    }
}
