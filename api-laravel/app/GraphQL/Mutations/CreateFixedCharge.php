<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Plan;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\FixedCharges\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::FixedCharges::Create
 * (app/graphql/mutations/fixed_charges/create.rb): "Creates a new Fixed
 * Charge for a Plan".
 */
class CreateFixedCharge
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.plans.parents.find_by(id: args[:plan_id]).
        $plan = Plan::query()
            ->where('organization_id', $organization->id)
            ->parents()
            ->find($input['plan_id'] ?? null);

        // Rails: cascade_updates is peeled off the params, not passed through.
        $cascadeUpdates = (bool) ($input['cascade_updates'] ?? false);
        unset($input['plan_id'], $input['cascade_updates']);

        $result = CreateService::call(plan: $plan, params: $input, cascadeUpdates: $cascadeUpdates);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->fixed_charge;
    }
}
