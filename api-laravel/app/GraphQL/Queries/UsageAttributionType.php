<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresAccountTree;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::UsageAttributionTypeResolver
 * (app/graphql/resolvers/usage_attribution_type_resolver.rb): "Query a
 * single usage attribution type of an organization" — a kept type of the
 * organization, gated by the account_tree feature flag; an unknown id
 * answers with the not_found envelope.
 *
 * Rails' REQUIRED_PERMISSION = "usage_attribution_types:view" is not
 * enforced yet — the roles/Permission slice does not populate context
 * permissions (same as the other query ports, e.g. Queries\Tax).
 */
class UsageAttributionType
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?\App\Models\UsageAttributionType
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresAccountTree::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = \App\Models\UsageAttributionType::query()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('usage_attribution_type');
        }

        return $found;
    }
}
