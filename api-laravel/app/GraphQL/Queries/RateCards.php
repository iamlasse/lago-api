<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\Queries\RateCardsQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::RateCardsResolver (app/graphql/resolvers/rate_cards_resolver.rb):
 * "Query rate cards of an organization" — product_catalog-gated; the filters
 * go through the RateCardsQuery port, wrapped in the frozen SDL's
 * RateCardCollection shape.
 */
class RateCards
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = RateCardsQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'product_ids' => $args['productIds'] ?? null,
                'product_filter_ids' => $args['productFilterIds'] ?? null,
                'product_category_ids' => $args['productCategoryIds'] ?? null,
                'without_product_category' => $args['withoutProductCategory'] ?? null,
                'code' => $args['code'] ?? null,
                'product_code' => $args['productCode'] ?? null,
                'product_filter_code' => $args['productFilterCode'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->rate_cards);
    }
}
