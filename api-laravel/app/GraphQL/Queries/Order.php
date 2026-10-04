<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\Models\Order as OrderModel;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::OrderResolver#order
 * (app/graphql/resolvers/order_resolver.rb): "Query a single order of an
 * organization" — current_organization.orders.find(id); an unknown id
 * answers with the not_found envelope. No feature gate on the GraphQL
 * surface (unlike the REST controller).
 */
class Order
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?OrderModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = OrderModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('order');
        }

        return $found;
    }
}
