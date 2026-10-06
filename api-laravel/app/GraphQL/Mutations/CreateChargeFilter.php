<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Charge;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\ChargeFilters\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ChargeFilters::Create
 * (app/graphql/mutations/charge_filters/create.rb): "Creates a new Charge
 * Filter".
 */
class CreateChargeFilter
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.charges.parents.find_by(id:
        // args[:charge_id]).
        $charge = Charge::query()
            ->where('organization_id', $organization->id)
            ->parents()
            ->find($input['charge_id'] ?? null);

        // Rails: cascade_updates is peeled off the params, not passed through.
        $cascadeUpdates = (bool) ($input['cascade_updates'] ?? false);
        unset($input['charge_id'], $input['cascade_updates']);

        $result = CreateService::call(charge: $charge, params: $input, cascadeUpdates: $cascadeUpdates);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge_filter;
    }
}
