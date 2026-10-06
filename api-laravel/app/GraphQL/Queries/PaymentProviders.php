<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\PaymentProvider as PaymentProviderModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::PaymentProvidersResolver
 * (app/graphql/resolvers/payment_providers_resolver.rb): "Query
 * organization's payment providers" — kaminari pagination and the optional
 * provider type filter (the enum wire value maps to the STI type string;
 * an unknown value raises NotImplementedError in Rails).
 */
class PaymentProviders
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $scope = PaymentProviderModel::query()
            ->where('organization_id', $organization->id);

        if (($args['type'] ?? null) !== null) {
            $scope->where('type', PaymentProviderModel::slugToType((string) $args['type']));
        }

        [$page, $limit] = Page::normalizePageAndLimit(
            isset($args['page']) && is_numeric($args['page']) ? (int) $args['page'] : null,
            isset($args['limit']) && is_numeric($args['limit']) ? (int) $args['limit'] : null,
        );

        return Page::fromLengthAwarePaginator($scope->paginate(perPage: $limit, page: $page));
    }
}
