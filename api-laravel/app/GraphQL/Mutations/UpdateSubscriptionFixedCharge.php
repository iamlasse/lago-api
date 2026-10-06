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
use App\Services\Subscriptions\UpdateOrOverrideFixedChargeService;

/**
 * Port of Rails' Mutations::Subscriptions::UpdateFixedCharge
 * (app/graphql/mutations/subscriptions/update_fixed_charge.rb): "Update a
 * fixed charge for a subscription".
 */
class UpdateSubscriptionFixedCharge
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

        // Rails: subscription&.plan&.fixed_charges&.find_by(code:
        // fixed_charge_code).
        $fixedCharge = $subscription?->plan
            ?->fixedCharges()
            ->where('code', $input['fixed_charge_code'] ?? null)
            ->first();

        unset($input['subscription_id'], $input['fixed_charge_code']);

        $result = UpdateOrOverrideFixedChargeService::call(
            subscription: $subscription,
            fixedCharge: $fixedCharge,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->fixed_charge;
    }
}
