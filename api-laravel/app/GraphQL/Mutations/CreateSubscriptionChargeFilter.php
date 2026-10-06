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
use App\Services\Subscriptions\ChargeFilters\CreateService;

/**
 * Port of Rails' Mutations::Subscriptions::CreateChargeFilter
 * (app/graphql/mutations/subscriptions/create_charge_filter.rb): "Create a
 * charge filter for a subscription".
 */
class CreateSubscriptionChargeFilter
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

        unset($input['subscription_id'], $input['charge_code']);

        $result = CreateService::call(
            subscription: $subscription,
            charge: $charge,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge_filter;
    }
}
