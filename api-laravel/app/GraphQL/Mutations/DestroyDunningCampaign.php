<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\DunningCampaign;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\DunningCampaigns\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::DunningCampaigns::Destroy
 * (app/graphql/mutations/dunning_campaigns/destroy.rb): "Deletes a dunning
 * campaign" — the payload's `id` is the destroyed campaign's id (the
 * discarded model still carries it).
 */
class DestroyDunningCampaign
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.dunning_campaigns.find_by(id:).
        $campaign = DunningCampaign::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(dunningCampaign: $campaign);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->dunning_campaign;
    }
}
