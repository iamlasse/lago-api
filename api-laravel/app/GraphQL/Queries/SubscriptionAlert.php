<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\UsageMonitoring\Alert;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Subscriptions::AlertResolver
 * (app/graphql/resolvers/subscriptions/alert_resolver.rb): "Query a single
 * subscription alert" — scoped to the org's subscription-type alerts.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("subscriptions:view").
 */
class SubscriptionAlert
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?Alert
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $alert = Alert::query()
            ->where('organization_id', $organization->id)
            ->usingSubscription()
            ->find($args['id'] ?? null);

        if ($alert === null) {
            throw Errors::notFoundError('alert');
        }

        return $alert;
    }
}
