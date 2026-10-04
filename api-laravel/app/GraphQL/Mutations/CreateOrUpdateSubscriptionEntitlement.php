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
use App\Services\Entitlements\SubscriptionEntitlementUpdateService;

/**
 * Port of Rails' Mutations::Entitlement::CreateOrUpdateSubscriptionEntitlement
 * (app/graphql/mutations/entitlement/create_or_update_subscription_
 * entitlement.rb): "Updates a subscription entitlement" — a FULL sync of
 * the one feature's privilege values (partial: false).
 */
class CreateOrUpdateSubscriptionEntitlement
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.subscriptions.find_by(id:
        // subscription_id) — nil reaches the service.
        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->find($input['subscription_id'] ?? null);

        $entitlement = is_array($input['entitlement'] ?? null) ? $input['entitlement'] : [];

        $privileges = [];

        foreach (is_array($entitlement['privileges'] ?? null) ? $entitlement['privileges'] : [] as $privilege) {
            // Rails: entitlement[:privileges]&.map { [it.privilege_code, it.value] }.to_h
            $privileges[$privilege['privilege_code']] = $privilege['value'];
        }

        $result = SubscriptionEntitlementUpdateService::call(
            subscription: $subscription,
            featureCode: (string) ($entitlement['feature_code'] ?? ''),
            privilegeParams: $privileges,
            partial: false,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->entitlement;
    }
}
