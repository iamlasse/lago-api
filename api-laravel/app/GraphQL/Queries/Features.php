<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Feature;
use App\GraphQL\Support\Page;
use App\Queries\FeaturesQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Entitlement::FeaturesResolver
 * (app/graphql/resolvers/entitlement/features_resolver.rb): "Query features
 * of an organization" — the search term goes through FeaturesQuery, the
 * page's features get their subscriptionsCount preloaded, and the result is
 * wrapped in the frozen SDL's FeatureObjectCollection shape.
 */
class Features
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = FeaturesQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: Feature.preload_subscriptions_count(current_organization,
        // result.features.includes(:privileges)).
        $features = Feature::preloadSubscriptionsCount(
            $organization,
            $result->features,
        );

        return Page::fromLengthAwarePaginator($result->features->setCollection(collect($features)));
    }
}
