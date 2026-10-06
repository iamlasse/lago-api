<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\FixedCharge;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\FixedCharges\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::FixedCharges::Update
 * (app/graphql/mutations/fixed_charges/update.rb): "Updates an existing Fixed
 * Charge".
 */
class UpdateFixedCharge
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.fixed_charges.parents.find_by(id:
        // args[:id]).
        $fixedCharge = FixedCharge::query()
            ->where('organization_id', $organization->id)
            ->parents()
            ->find($input['id'] ?? null);

        // Rails: cascade_updates is peeled off the params, not passed through;
        // the service call carries timestamp: Time.current.to_i.
        $cascadeUpdates = (bool) ($input['cascade_updates'] ?? false);
        unset($input['id'], $input['cascade_updates']);

        $result = UpdateService::call(
            fixedCharge: $fixedCharge,
            params: $input,
            timestamp: now()->getTimestamp(),
            cascadeUpdates: $cascadeUpdates,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->fixed_charge;
    }
}
