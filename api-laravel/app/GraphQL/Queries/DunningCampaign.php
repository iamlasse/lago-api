<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\DunningCampaign as DunningCampaignModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::DunningCampaignResolver
 * (app/graphql/resolvers/dunning_campaign_resolver.rb): "Query a single
 * dunning campaign of an organization" — current_organization.dunning_campaigns.find(id),
 * so a kept campaign resolves and an unknown (or discarded) id answers the
 * not_found envelope. The wire type is non-null DunningCampaign!.
 */
class DunningCampaign
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): DunningCampaignModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = DunningCampaignModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('dunning_campaign');
        }

        return $found;
    }
}
