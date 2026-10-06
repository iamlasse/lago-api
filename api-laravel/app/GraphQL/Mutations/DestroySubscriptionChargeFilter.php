<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Subscription;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Subscriptions\ChargeFilters\DestroyService;

/**
 * Port of Rails' Mutations::Subscriptions::DestroyChargeFilter
 * (app/graphql/mutations/subscriptions/destroy_charge_filter.rb): "Destroy a
 * charge filter for a subscription".
 */
class DestroySubscriptionChargeFilter
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.subscriptions.find_by(id:).
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->find($input['subscription_id'] ?? null);

        // Rails: subscription&.plan&.charges&.find_by(code: charge_code).
        $charge = $subscription?->plan
            ?->charges()
            ->where('code', $input['charge_code'] ?? null)
            ->first();

        $chargeFilter = $this->findChargeFilter($charge, $input['values'] ?? null);

        $result = DestroyService::call(
            subscription: $subscription,
            charge: $charge,
            chargeFilter: $chargeFilter,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge_filter;
    }

    /**
     * Rails: find_charge_filter — the filter whose values hash matches the
     * given values (nil when the charge is unknown or nothing matches).
     */
    private function findChargeFilter(?object $charge, mixed $values): ?object
    {
        if ($charge === null || $values === null) {
            return null;
        }

        $sortedValues = $this->valuesHash((array) $values);

        foreach ($charge->filters as $filter) {
            if ($this->valuesHash($filter->toH()) === $sortedValues) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * Rails: `values.sort` — the values hash with sorted entries and sorted
     * values inside each entry.
     *
     * @param  array<string, mixed>  $hash
     */
    private function valuesHash(array $hash): string
    {
        ksort($hash);

        $normalized = [];

        foreach ($hash as $key => $filterValues) {
            $filterValues = array_values((array) $filterValues);
            sort($filterValues);
            $normalized[$key] = $filterValues;
        }

        return (string) json_encode($normalized);
    }
}
