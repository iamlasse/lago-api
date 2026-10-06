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
use App\Services\Subscriptions\UpdateOrOverrideChargeService;

/**
 * Port of Rails' Mutations::Subscriptions::UpdateCharge
 * (app/graphql/mutations/subscriptions/update_charge.rb): "Update a charge
 * for a subscription".
 */
class UpdateSubscriptionCharge
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.subscriptions.find_by(id:) — a nil
        // subscription flows into the service and answers not_found there.
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->find($input['subscription_id'] ?? null);

        // Rails: subscription&.plan&.charges&.find_by(code: charge_code) — a
        // discarded charge no longer resolves (the default kept scope).
        $charge = $subscription?->plan
            ?->charges()
            ->where('code', $input['charge_code'] ?? null)
            ->first();

        unset($input['subscription_id'], $input['charge_code']);

        $result = UpdateOrOverrideChargeService::call(
            subscription: $subscription,
            charge: $charge,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->charge;
    }
}
