<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\AppliedCouponsQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::AppliedCouponsResolver
 * (app/graphql/resolvers/applied_coupons_resolver.rb): "Query applied
 * coupons of an organization" — the status / external_customer_id /
 * coupon_code filters go through the AppliedCouponsQuery port, wrapped in
 * the frozen SDL's AppliedCouponCollection shape (`collection` +
 * `metadata`).
 */
class AppliedCoupons
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = AppliedCouponsQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'status' => $args['status'] ?? null,
                'external_customer_id' => $args['externalCustomerId'] ?? null,
                'coupon_code' => $args['couponCode'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->applied_coupons);
    }
}
