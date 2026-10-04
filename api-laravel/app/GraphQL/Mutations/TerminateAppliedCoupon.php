<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\Models\AppliedCoupon;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\AppliedCoupons\TerminateService as AppliedCouponTerminateService;

/**
 * Port of Rails' Mutations::AppliedCoupons::Terminate
 * (app/graphql/mutations/applied_coupons/terminate.rb): "Unassign a coupon
 * from a customer" — current_organization.applied_coupons.find_by(id:)
 * reaches the service as nil when unknown (the not_found envelope).
 */
class TerminateAppliedCoupon
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.applied_coupons.find_by(id:).
        $appliedCoupon = AppliedCoupon::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = AppliedCouponTerminateService::call(appliedCoupon: $appliedCoupon);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->applied_coupon;
    }
}
