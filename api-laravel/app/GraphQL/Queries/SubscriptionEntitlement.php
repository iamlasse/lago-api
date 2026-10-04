<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Subscription;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Models\Entitlement\SubscriptionEntitlement as SubscriptionEntitlementDTO;

/**
 * Port of Rails' Resolvers::Entitlement::SubscriptionEntitlementResolver
 * (app/graphql/resolvers/entitlement/subscription_entitlement_resolver.rb):
 * "Retrieve an entitlement of a subscriptions" — the merged view of the
 * plan's entitlement and the subscription's override, or not_found.
 */
class SubscriptionEntitlement
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): SubscriptionEntitlementDTO
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $subscription = Subscription::query()
            ->where('organization_id', $organization->id)
            ->find($args['subscriptionId'] ?? null);

        if ($subscription === null) {
            throw Errors::notFoundError('subscription');
        }

        // Rails carries a TODO here — replace once
        // SubscriptionEntitlementQuery grows a where clause; find the code
        // in the merged view.
        foreach (SubscriptionEntitlementDTO::forSubscription($subscription) as $entitlement) {
            if ($entitlement->code === ($args['featureCode'] ?? null)) {
                return $entitlement;
            }
        }

        throw Errors::notFoundError('entitlement');
    }
}
