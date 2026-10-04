<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Coupon;
use App\Models\Customer;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\AppliedCoupons\CreateService as AppliedCouponCreateService;

/**
 * Port of Rails' Mutations::AppliedCoupons::Create
 * (app/graphql/mutations/applied_coupons/create.rb): "Assigns a Coupon to a
 * Customer" — the customer and coupon are looked up by Lago id within the
 * current organization and reach the service as nil when unknown.
 */
class CreateAppliedCoupon
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id:) /
        // current_organization.coupons.find_by(id:).
        $customer = Customer::query()
            ->where('organization_id', $organization->id)
            ->find($input['customer_id'] ?? null);

        $coupon = Coupon::query()
            ->where('organization_id', $organization->id)
            ->find($input['coupon_id'] ?? null);

        $result = AppliedCouponCreateService::call(customer: $customer, coupon: $coupon, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->applied_coupon;
    }
}
