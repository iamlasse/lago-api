<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use GraphQL\Error\Error;
use App\Models\Organization;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Customer as CustomerModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerResolver
 * (app/graphql/resolvers/customer_resolver.rb): "Query a single customer of
 * an organization" — by `id` or by `externalId`.
 */
class Customer
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?CustomerModel
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

        $query = $organization->customers();

        // Rails: find(id) if id.present?, else find_by!(external_id:) —
        // RecordNotFound becomes the not_found error envelope.
        $found = $id !== null && $id !== ''
            ? $query->find($id)
            : $query->where('external_id', $externalId)->first();

        if ($found === null) {
            throw Errors::notFoundError('customer');
        }

        return $found;
    }
}
