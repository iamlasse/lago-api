<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Coupon as CouponModel;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CouponResolver
 * (app/graphql/resolvers/coupon_resolver.rb): "Query a single coupon of an
 * organization" — current_organization.coupons.with_discarded.find(id), so
 * a discarded coupon still resolves and an unknown id answers with the
 * not_found envelope.
 */
class Coupon
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?CouponModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        // Rails: current_organization.coupons.with_discarded.find(id) — the
        // organization has no ported coupons() relation, so the scope is
        // expressed on the model (same organization_id constraint).
        $found = CouponModel::query()
            ->withTrashed()
            ->where('organization_id', $organization->id)
            ->find($args['id'] ?? null);

        if ($found === null) {
            throw Errors::notFoundError('coupon');
        }

        return $found;
    }
}
