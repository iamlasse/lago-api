<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use App\Models\RateCardRate as RateCardRateModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::RateCardRateResolver
 * (app/graphql/resolvers/rate_card_rate_resolver.rb): "Query a single rate of
 * a rate card" — product_catalog-gated; an unknown id answers the not_found
 * envelope.
 */
class RateCardRate
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?RateCardRateModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.rate_card_rates.find(id).
        $found = RateCardRateModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('rate_card_rate');
        }

        return $found;
    }
}
