<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Customer;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\CustomerUsageService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Customers::UsageResolver
 * (app/graphql/resolvers/customers/usage_resolver.rb): "Query the projected usage of
 * the customer on the current billing period" — Invoices::
 * CustomerUsageService.with_ids, taxes unapplied, usage buckets requested, with_projection on.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("customers:view").
 * TODO(port): `use_usage_buckets: true` — the usage-buckets leg is the
 * ClickHouse slice; the ported service always aggregates live.
 */
class CustomerProjectedUsage
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: Customer.find_by(id:, organization_id:) then
        // customer.active_subscriptions.find_by(id: subscription_id).
        $customer = Customer::query()
            ->where('organization_id', $organization->id)
            ->where('id', $args['customerId'] ?? null)
            ->first();

        // Rails: customer.active_subscriptions (subscriptions.active — status
        // :active), then find_by(id: subscription_id).
        $subscription = $customer?->subscriptions()
            ->active()
            ->where('id', $args['subscriptionId'] ?? null)
            ->first();

        $result = CustomerUsageService::call(
            customer: $customer,
            subscription: $subscription,
            applyTaxes: false,
            withProjection: true,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->usage;
    }
}
