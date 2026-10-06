<?php

declare(strict_types=1);

namespace App\GraphQL\Support;

use App\GraphQL\Execution\Errors;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' RequiresProductCatalog concern
 * (app/graphql/concerns/requires_product_catalog.rb) — guards the catalog
 * mutations/resolvers: requires the organization's product_catalog feature
 * flag. Rails raises from `ready?`, before any lookup runs, so the gate fires
 * ahead of the resolver body (a disabled catalog is a 403 even for an unknown
 * record id).
 */
final class RequiresProductCatalog
{
    /** @throws ExecutionError */
    public static function authorize(GraphQLContext $context): void
    {
        $organization = LagoContext::currentOrganization($context);

        $flags = (array) ($organization->feature_flags ?? []);

        if (! in_array('product_catalog', $flags, true)) {
            // Rails: raise forbidden_error(code: "feature_unavailable").
            throw Errors::forbiddenError('feature_unavailable');
        }
    }
}
