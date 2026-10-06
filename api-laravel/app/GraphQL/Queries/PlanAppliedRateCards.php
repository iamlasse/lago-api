<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\Queries\PlanRateCardsQuery;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PlanAppliedRateCardsResolver
 * (app/graphql/resolvers/plan_applied_rate_cards_resolver.rb): "Query rate
 * cards applied to a plan" — product_catalog-gated; the shared catalog list
 * filters (RateCardListArguments) go through the PlanRateCardsQuery port with
 * the product_category ordering.
 */
class PlanAppliedRateCards
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = PlanRateCardsQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'plan_id' => $args['planId'] ?? null,
                'has_rate_overrides' => $args['hasRateOverrides'] ?? null,
                'product_category_ids' => $args['productCategoryIds'] ?? null,
                'product_filter_ids' => $args['productFilterIds'] ?? null,
                'product_ids' => $args['productIds'] ?? null,
                'product_type' => $args['productType'] ?? null,
                'without_product_category' => $args['withoutProductCategory'] ?? null,
                'without_product_filter' => $args['withoutProductFilter'] ?? null,
            ],
            order: 'product_category',
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->plan_rate_cards);
    }
}
