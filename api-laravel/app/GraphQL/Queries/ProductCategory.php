<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Models\ProductCategory as ProductCategoryModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ProductCategoryResolver
 * (app/graphql/resolvers/product_category_resolver.rb): "Query a single
 * product_category of an organization" — product_catalog-gated; an unknown id
 * answers the not_found envelope.
 */
class ProductCategory
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?ProductCategoryModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.product_categories.find(id) — a
        // discarded category no longer resolves (the default kept scope).
        $found = ProductCategoryModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('product_category');
        }

        return $found;
    }
}
