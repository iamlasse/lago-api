<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\ChargeFilter;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\ChargeFilters\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ChargeFilters::Update
 * (app/graphql/mutations/charge_filters/update.rb): "Updates an existing
 * Charge Filter".
 */
class UpdateChargeFilter
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.charge_filters.find_by(id: args[:id]).
        $chargeFilter = ChargeFilter::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $cascadeUpdates = (bool) ($input['cascade_updates'] ?? false);
        unset($input['id'], $input['cascade_updates']);

        $result = UpdateService::call(chargeFilter: $chargeFilter, params: $input, cascadeUpdates: $cascadeUpdates);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge_filter;
    }
}
