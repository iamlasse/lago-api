<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\PlanRateCard;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PlanRateCards\DestroyService;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PlanAppliedRateCards::Destroy
 * (app/graphql/mutations/plan_applied_rate_cards/destroy.rb): "Removes a rate
 * card from a plan without contracts" — product_catalog-gated.
 */
class DestroyPlanAppliedRateCard
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: PlanRateCard.where(organization:).find_by(id:).
        $planRateCard = PlanRateCard::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(planRateCard: $planRateCard);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->plan_rate_card;
    }
}
