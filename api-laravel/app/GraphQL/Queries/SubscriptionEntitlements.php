<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Subscription;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\Entitlement\SubscriptionEntitlement;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Entitlement::SubscriptionEntitlementsResolver
 * (app/graphql/resolvers/entitlement/subscription_entitlements_resolver.rb):
 * "Query entitlements of a subscriptions" — the unpaginated merged view,
 * wrapped in the frozen SDL's SubscriptionEntitlementCollection shape.
 */
class SubscriptionEntitlements
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
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

        $entitlements = SubscriptionEntitlement::forSubscription($subscription);
        $count = count($entitlements);

        return new Page(
            $entitlements,
            (object) [
                'currentPage' => 1,
                'limitValue' => $count,
                'totalPages' => 1,
                'totalCount' => $count,
            ],
        );
    }
}
