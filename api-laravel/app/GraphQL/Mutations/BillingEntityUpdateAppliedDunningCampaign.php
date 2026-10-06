<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\BillingEntities\UpdateAppliedDunningCampaignService;

/**
 * Port of Rails' Mutations::BillingEntities::UpdateAppliedDunningCampaign
 * (app/graphql/mutations/billing_entities/update_applied_dunning_campaign.rb):
 * "Updates the applied dunning campaign for a billing entity".
 */
class BillingEntityUpdateAppliedDunningCampaign
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.billing_entities.find_by(id:) — a nil
        // entity flows into the service and answers not_found there.
        $billingEntity = \App\Models\BillingEntity::query()
            ->where('organization_id', $organization->id)
            ->find($input['billing_entity_id'] ?? null);

        $result = UpdateAppliedDunningCampaignService::call(
            billingEntity: $billingEntity,
            appliedDunningCampaignId: $input['applied_dunning_campaign_id'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->billing_entity;
    }
}
