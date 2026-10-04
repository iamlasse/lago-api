<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Coupon;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Coupons\TerminateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Coupons::Terminate
 * (app/graphql/mutations/coupons/terminate.rb): "Deletes a coupon" — marks
 * the coupon terminated (Coupons::TerminateService).
 */
class TerminateCoupon
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.coupons.find_by(id:).
        $coupon = Coupon::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = TerminateService::call(coupon: $coupon);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->coupon;
    }
}
