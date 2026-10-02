<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use Illuminate\Support\Str;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Subscriptions\CreateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Subscriptions::Create
 * (app/graphql/mutations/subscriptions/create.rb): "Create a new
 * Subscription" — the customer and plan are resolved on the current
 * organization, the external id falls back to a UUID, and the (unsupported
 * on the wire) `entitlements` kwarg is dropped exactly like Rails.
 */
class CreateSubscription
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id: args[:customer_id])
        // current_organization.plans.find_by(id: args[:plan_id])
        $customer = $organization->customers()->find($input['customer_id'] ?? null);
        $plan = $organization->plans()->find($input['plan_id'] ?? null);

        // Rails: Subscriptions::ValidateService#valid_customer?/valid_plan? —
        // a missing customer or plan is a not_found failure. The guard lives
        // here because the ported CreateService constructor requires both
        // models; the wire envelope is identical.
        if ($customer === null) {
            throw Errors::notFoundError('customer');
        }

        if ($plan === null) {
            throw Errors::notFoundError('plan');
        }

        $result = CreateService::call(
            customer: $customer,
            plan: $plan,
            params: [
                ...$input,
                // Rails: args.merge(external_id: args[:external_id] || SecureRandom.uuid)
                'external_id' => ($input['external_id'] ?? null) ?? Str::uuid()->toString(),
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: subscription.reload
        return $result->subscription->refresh();
    }
}
