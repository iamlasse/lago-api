<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Charge;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Charges\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Charges::Update (app/graphql/mutations/charges/update.rb):
 * "Updates an existing Charge".
 */
class UpdateCharge
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.charges.parents.find_by(id: args[:id]).
        $charge = Charge::query()
            ->where('organization_id', $organization->id)
            ->parents()
            ->find($input['id'] ?? null);

        // Rails: cascade_updates is peeled off the params, not passed through.
        $cascadeUpdates = (bool) ($input['cascade_updates'] ?? false);
        unset($input['id'], $input['cascade_updates']);

        $result = UpdateService::call(charge: $charge, params: $input, cascadeUpdates: $cascadeUpdates);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge;
    }
}
