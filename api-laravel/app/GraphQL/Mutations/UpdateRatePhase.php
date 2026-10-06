<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\PlanRateCard;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\RatePhases\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::RatePhases::Update (app/graphql/mutations/rate_phases/update.rb):
 * "Updates a single phase, addressed by its code within the entry" —
 * product_catalog-gated.
 */
class UpdateRatePhase
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

        // Rails: plan_rate_card&.rate_phases&.find_by(code: args[:code]).
        $ratePhase = $planRateCard?->ratePhases()
            ->where('code', $input['code'] ?? null)
            ->first();

        // Rails: params: args.except(:plan_applied_rate_card_id, :code,
        // :new_code); params[:code] = args[:new_code] if present.
        $newCode = $input['new_code'] ?? null;
        unset($input['plan_applied_rate_card_id'], $input['code'], $input['new_code']);

        if ($newCode !== null) {
            $input['code'] = $newCode;
        }

        $result = UpdateService::call(ratePhase: $ratePhase, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->rate_phase;
    }
}
