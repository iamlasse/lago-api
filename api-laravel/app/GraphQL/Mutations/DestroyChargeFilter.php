<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\ChargeFilter;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\ChargeFilters\DestroyService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::ChargeFilters::Destroy
 * (app/graphql/mutations/charge_filters/destroy.rb): "Deletes a Charge
 * Filter" — the payload's `id` is the destroyed filter's id.
 */
class DestroyChargeFilter
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.charge_filters.find_by(id:).
        $chargeFilter = ChargeFilter::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = DestroyService::call(
            chargeFilter: $chargeFilter,
            cascadeUpdates: (bool) ($input['cascade_updates'] ?? false),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge_filter;
    }
}
