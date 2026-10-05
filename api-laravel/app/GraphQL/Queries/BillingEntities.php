<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Illuminate\Pagination\LengthAwarePaginator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::BillingEntitiesResolver
 * (app/graphql/resolvers/billing_entities_resolver.rb): "Query active
 * billing_entities of an organization" — the active-scoped has_many with no
 * arguments and no pagination on the Rails side; the frozen SDL still wraps
 * it in the BillingEntityCollection shape (`collection` + `metadata`), so the
 * whole set is wrapped in a single-page paginator.
 *
 * Rails' REQUIRED_PERMISSION = "billing_entities:view"
 * (CanRequirePermissions) is not enforced yet — the roles/Permission slice
 * does not populate context permissions (same as the other query ports,
 * e.g. Queries\AddOns).
 */
class BillingEntities
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $entities = $organization->billingEntities()->get();

        $paginator = new LengthAwarePaginator(
            $entities->all(),
            max($entities->count(), 1),
            max($entities->count(), 1),
            1,
        );

        return Page::fromLengthAwarePaginator($paginator);
    }
}
