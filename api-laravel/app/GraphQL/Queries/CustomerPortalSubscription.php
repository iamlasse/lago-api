<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\CustomerPortalUser;
use App\Models\Subscription as SubscriptionModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::SubscriptionResolver
 * (app/graphql/resolvers/customer_portal/subscription_resolver.rb): "Query a
 * single subscription from the customer portal" — the portal customer's own
 * subscription by id; anything else answers the not_found envelope.
 */
class CustomerPortalSubscription
{
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?SubscriptionModel
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        // Rails: customer.subscriptions.find(id) — a non-uuid id raises
        // RecordNotFound (never a PG cast error); guard the id shape.
        $id = $args['id'] ?? null;

        if (! is_string($id) || preg_match(self::UUID_REGEX, $id) !== 1) {
            throw Errors::notFoundError('subscription');
        }

        $found = $customer->subscriptions()->find($id);

        if ($found === null) {
            throw Errors::notFoundError('subscription');
        }

        return $found;
    }
}
