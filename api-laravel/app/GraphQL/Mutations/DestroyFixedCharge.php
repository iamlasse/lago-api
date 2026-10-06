<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\FixedCharge;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\FixedCharges\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::FixedCharges::Destroy
 * (app/graphql/mutations/fixed_charges/destroy.rb): "Deletes a Fixed Charge"
 * — the payload's `id` is the destroyed fixed charge's id.
 */
class DestroyFixedCharge
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.fixed_charges.parents.find_by(id:).
        $fixedCharge = FixedCharge::query()
            ->where('organization_id', $organization->id)
            ->parents()
            ->find($input['id'] ?? null);

        $result = DestroyService::call(
            fixedCharge: $fixedCharge,
            cascadeUpdates: (bool) ($input['cascade_updates'] ?? false),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->fixed_charge;
    }
}
