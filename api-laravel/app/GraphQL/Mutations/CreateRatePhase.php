<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\PlanRateCard;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\RatePhases\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RatePhases::Create (app/graphql/mutations/rate_phases/create.rb):
 * "Inserts a phase into a plan rate card's sequence" — product_catalog-gated.
 */
class CreateRatePhase
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: PlanRateCard.where(organization:).find_by(id: ...).
        $planRateCard = PlanRateCard::query()
            ->where('organization_id', $organization->id)
            ->find($input['plan_applied_rate_card_id'] ?? null);

        // Rails: params: args.except(:plan_applied_rate_card_id).
        unset($input['plan_applied_rate_card_id']);

        $result = CreateService::call(planRateCard: $planRateCard, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->rate_phase;
    }
}
