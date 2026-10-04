<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\DunningCampaignsQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::DunningCampaignsResolver
 * (app/graphql/resolvers/dunning_campaigns_resolver.rb): "Query dunning
 * campaigns of an organization" — the appliedToOrganization / currency
 * filters, the name/code search term and the name|code order go through the
 * DunningCampaignsQuery port, wrapped in the frozen SDL's
 * DunningCampaignCollection shape (`collection` + `metadata`).
 */
class DunningCampaigns
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = DunningCampaignsQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            order: $args['order'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'applied_to_organization' => $args['appliedToOrganization'] ?? null,
                'currency' => $args['currency'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->dunning_campaigns);
    }
}
