<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\RateCard as RateCardModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::RateCardResolver (app/graphql/resolvers/rate_card_resolver.rb):
 * "Query a single rate card of an organization" — product_catalog-gated; an
 * unknown id answers the not_found envelope.
 */
class RateCard
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?RateCardModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.rate_cards.find(id) — a discarded card
        // no longer resolves (the default kept scope).
        $found = RateCardModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('rate_card');
        }

        return $found;
    }
}
