<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Models\BillingEntity as BillingEntityModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::BillingEntityResolver
 * (app/graphql/resolvers/billing_entity_resolver.rb): "Query a single
 * billing_entity of an organization" — all_billing_entities.find_by!(code:),
 * so discarded entities still resolve; an unknown code answers with the
 * not_found envelope.
 *
 * Rails' REQUIRED_PERMISSION = "billing_entities:view"
 * (CanRequirePermissions) is not enforced yet — the roles/Permission slice
 * does not populate context permissions (same as the other query ports,
 * e.g. Queries\AddOn).
 */
class BillingEntity
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?BillingEntityModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $found = $organization->allBillingEntities()
            ->where('code', $args['code'] ?? null)
            ->first();

        if ($found === null) {
            throw Errors::notFoundError('billing_entity');
        }

        return $found;
    }
}
