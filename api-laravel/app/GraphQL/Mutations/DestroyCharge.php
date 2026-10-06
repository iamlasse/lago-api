<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Charge;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Charges\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Charges::Destroy (app/graphql/mutations/charges/destroy.rb):
 * "Deletes a Charge" — the payload's `id` is the destroyed charge's id.
 */
class DestroyCharge
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.charges.parents.find_by(id:).
        $charge = Charge::query()
            ->where('organization_id', $organization->id)
            ->parents()
            ->find($input['id'] ?? null);

        $result = DestroyService::call(
            charge: $charge,
            cascadeUpdates: (bool) ($input['cascade_updates'] ?? false),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge;
    }
}
