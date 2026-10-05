<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\Models\Plan as PlanModel;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PlanResolver
 * (app/graphql/resolvers/plan_resolver.rb): "Query a single plan of an
 * organization" — current_organization.plans.find(id); an unknown id answers
 * with the not_found envelope.
 *
 * Rails' REQUIRED_PERMISSION = "plans:view"
 * (CanRequirePermissions) is not enforced yet — the roles/Permission slice
 * does not populate context permissions (same as the other query ports,
 * e.g. Queries\AddOn).
 */
class Plan
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?PlanModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.plans.find(id) — a discarded plan no
        // longer resolves (the default kept scope).
        $found = PlanModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('plan');
        }

        return $found;
    }
}
