<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\AddOn;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\AddOns\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::AddOns::Update
 * (app/graphql/mutations/add_ons/update.rb): "Update an existing add-on" —
 * resolved by id in the current organization, the whole input goes to
 * AddOns::UpdateService.
 */
class UpdateAddOn
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.add_ons.find_by(id: input[:id]).
        $addOn = AddOn::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(addOn: $addOn, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->add_on;
    }
}
