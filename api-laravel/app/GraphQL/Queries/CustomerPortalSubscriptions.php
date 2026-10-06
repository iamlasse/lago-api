<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\CustomerPortalUser;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Subscriptions\Query as SubscriptionsQuery;

/**
 * Port of Rails' Resolvers::CustomerPortal::SubscriptionsResolver
 * (app/graphql/resolvers/customer_portal/subscriptions_resolver.rb): "Query
 * customer portal subscriptions" — the portal customer's own subscriptions
 * (external_customer_id + customer filters), wrapped in the frozen SDL's
 * SubscriptionCollection shape.
 *
 * Rails passes `organization: nil` and scopes through `filters.customer`
 * (SubscriptionsQuery's base scope falls back to
 * `Subscription.where(customer:)`); the ported query type-hints
 * Organization, so the customer's organization is passed instead — the same
 * external_customer_id filter pins the result set to the portal customer's
 * subscriptions (org + external_id are unique).
 *
 * Unlike the admin resolver, Rails does NOT pass
 * `exclude_next_subscriptions` here — neither does the portal resolver.
 */
class CustomerPortalSubscriptions
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        $filters = Args::snakeKeys($args);
        unset($filters['page'], $filters['limit']);

        $filters['external_customer_id'] = $customer->external_id;
        $filters['customer'] = $customer;

        $result = SubscriptionsQuery::call(
            organization: $customer->organization,
            filters: $filters,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->subscriptions);
    }
}
