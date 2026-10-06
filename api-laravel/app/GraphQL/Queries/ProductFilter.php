<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Models\ProductFilter as ProductFilterModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ProductFilterResolver
 * (app/graphql/resolvers/product_filter_resolver.rb): "Query a single product
 * filter of an organization" — product_catalog-gated; an unknown id answers
 * the not_found envelope.
 */
class ProductFilter
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?ProductFilterModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.product_filters.find(id) — a discarded
        // filter no longer resolves (the default kept scope).
        $found = ProductFilterModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('product_filter');
        }

        return $found;
    }
}
