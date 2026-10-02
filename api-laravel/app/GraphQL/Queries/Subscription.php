<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use GraphQL\Error\Error;
use App\Models\Organization;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\Subscription as SubscriptionModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::SubscriptionResolver
 * (app/graphql/resolvers/subscription_resolver.rb): "Query a single
 * subscription of an organization" — by `id` or, with the external-id lookup
 * ordering (terminated_at DESC NULLS FIRST, started_at DESC), by
 * `externalId`.
 */
class Subscription
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?SubscriptionModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $id = $args['id'] ?? null;
        $externalId = $args['externalId'] ?? null;

        // Rails: raise GraphQL::ExecutionError without extensions.
        if ($id === null && $externalId === null) {
            throw new Error('You must provide either `id` or `external_id`.');
        }

        $query = $organization->subscriptions();

        // Rails: find(id) if id.present?, else find_by!(external_id:) with
        // the external-id lookup ordering — RecordNotFound becomes the
        // not_found error envelope.
        $found = ($id !== null && $id !== '')
            ? $query->find($id)
            : $query->where('external_id', $externalId)
                ->orderByRaw('terminated_at desc nulls first')
                ->orderByDesc('started_at')
                ->first();

        if ($found === null) {
            throw Errors::notFoundError('subscription');
        }

        return $found;
    }
}
