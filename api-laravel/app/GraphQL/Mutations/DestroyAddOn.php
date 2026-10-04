<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\AddOn;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\AddOns\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::AddOns::Destroy
 * (app/graphql/mutations/add_ons/destroy.rb): "Deletes an add-on" — the
 * payload's `id` is the destroyed add-on's id (Rails resolves
 * result.add_on; the frozen payload type only carries id).
 */
class DestroyAddOn
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.add_ons.find_by(id:).
        $addOn = AddOn::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(addOn: $addOn);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->add_on;
    }
}
