<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\PricingUnit;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\PricingUnits\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::PricingUnits::Update
 * (app/graphql/mutations/pricing_units/update.rb): "Updates a new pricing
 * unit" — resolved by id in the current organization, the whole input goes
 * to PricingUnits::UpdateService.
 */
class UpdatePricingUnit
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.pricing_units.find_by(id: input[:id]).
        $pricingUnit = PricingUnit::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(pricingUnit: $pricingUnit, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->pricing_unit;
    }
}
