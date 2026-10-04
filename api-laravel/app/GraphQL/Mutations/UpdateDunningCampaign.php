<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\DunningCampaign;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\DunningCampaigns\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::DunningCampaigns::Update
 * (app/graphql/mutations/dunning_campaigns/update.rb): "Updates a dunning
 * campaign and its thresholds" — the campaign is resolved from the current
 * organization (find_by, nil allowed) and the whole input goes to
 * DunningCampaigns::UpdateService.
 */
class UpdateDunningCampaign
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.dunning_campaigns.find_by(id: args[:id]).
        $campaign = DunningCampaign::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(
            organization: $organization,
            dunningCampaign: $campaign,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->dunning_campaign;
    }
}
