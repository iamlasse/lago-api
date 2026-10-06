<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\CustomerPortalUser;
use App\Services\Invoices\CustomerUsageService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::Customers::UsageResolver
 * (app/graphql/resolvers/customer_portal/customers/usage_resolver.rb): "Query
 * the usage of the customer on the current billing period" — the portal
 * customer's own usage through Invoices::CustomerUsageService, taxes
 * unapplied, usage buckets requested.
 *
 * Rails' `with_ids` raises RecordNotFound for an unknown (or non-active)
 * subscription, answered as the not_found(customer) envelope.
 *
 * TODO(port): `use_usage_buckets: true` — the usage-buckets leg is the
 * ClickHouse slice; the ported service always aggregates live.
 */
class CustomerPortalCustomerUsage
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        // Rails: with_ids(organization_id:, customer_id:, subscription_id:)
        // → customer.active_subscriptions.find(id).
        $subscription = $customer->subscriptions()
            ->where('status', \App\Enums\SubscriptionStatus::Active->value)
            ->find($args['subscriptionId'] ?? null);

        if ($subscription === null) {
            throw Errors::notFoundError('customer');
        }

        $result = CustomerUsageService::call(
            customer: $customer,
            subscription: $subscription,
            applyTaxes: false,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->usage;
    }
}
