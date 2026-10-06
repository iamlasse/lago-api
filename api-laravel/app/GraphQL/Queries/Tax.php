<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Tax as TaxModel;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::TaxResolver (app/graphql/resolvers/tax_resolver.rb):
 * "Query a single tax of an organization" — current_organization.taxes.find(id);
 * an unknown id answers with the not_found envelope.
 *
 * Rails' REQUIRED_PERMISSION = "taxes:view" is not enforced yet — the
 * roles/Permission slice does not populate context permissions (same as the
 * other query ports, e.g. Queries\AddOn).
 */
class Tax
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?TaxModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.taxes.find(id) — a discarded tax no
        // longer resolves (the default kept scope).
        $found = TaxModel::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('tax');
        }

        return $found;
    }
}
