<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Contract as ContractModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ContractResolver (app/graphql/resolvers/contract_resolver.rb):
 * "Query a single contract of an organization" — product_catalog-gated; an
 * unknown id answers the not_found envelope.
 */
class Contract
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?ContractModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.contracts.find(id) — a discarded
        // contract no longer resolves (the default kept scope).
        $found = ContractModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('contract');
        }

        return $found;
    }
}
