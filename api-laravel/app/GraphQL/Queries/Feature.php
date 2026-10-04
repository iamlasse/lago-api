<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Feature as FeatureModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Entitlement::FeatureResolver
 * (app/graphql/resolvers/entitlement/feature_resolver.rb): "Query a single
 * feature" — by id or code (one of the two), not_found otherwise.
 */
class Feature
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): FeatureModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $feature = isset($args['id'])
            ? FeatureModel::query()
                ->where('organization_id', $organization->id)
                ->find($args['id'])
            : FeatureModel::query()
                ->where('organization_id', $organization->id)
                ->where('code', $args['code'] ?? null)
                ->first();

        if ($feature === null) {
            throw Errors::notFoundError('feature');
        }

        return $feature;
    }
}
