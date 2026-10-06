<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Guards\CustomerPortalUser;
use App\GraphQL\Support\LagoContext;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::OrganizationResolver
 * (app/graphql/resolvers/customer_portal/organization_resolver.rb): "Query
 * customer portal organization" — the portal customer's organization.
 */
class CustomerPortalOrganization
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        return $customer->organization;
    }
}
