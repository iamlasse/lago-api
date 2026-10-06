<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\Queries\ProductsQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ProductsResolver (app/graphql/resolvers/products_resolver.rb):
 * "Query products of an organization" — product_catalog-gated; the filters go
 * through the ProductsQuery port, wrapped in the frozen SDL's
 * ProductCollection shape.
 */
class Products
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = ProductsQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'product_category_ids' => $args['productCategoryIds'] ?? null,
                'without_product_category' => $args['withoutProductCategory'] ?? null,
                'product_type' => $args['productType'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->products);
    }
}
