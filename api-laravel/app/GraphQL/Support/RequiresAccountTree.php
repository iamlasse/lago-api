<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use App\GraphQL\Execution\Errors;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' `raise forbidden_error(code: "feature_unavailable") unless
 * current_organization.account_tree_enabled?` — guards the usage-attribution
 * resolvers/mutations behind the organization's account_tree feature flag
 * (Rails raises from the resolver body / `ready?`, before any lookup).
 */
final class RequiresAccountTree
{
    /** @throws ExecutionError */
    public static function authorize(GraphQLContext $context): void
    {
        $organization = LagoContext::currentOrganization($context);

        if (! $organization->accountTreeEnabled()) {
            throw Errors::forbiddenError('feature_unavailable');
        }
    }
}
