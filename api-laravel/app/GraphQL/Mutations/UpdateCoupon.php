<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Coupon;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Coupons\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Coupons::Update
 * (app/graphql/mutations/coupons/update.rb): "Update an existing coupon" —
 * current_organization.coupons.find_by(id:) reaches the service as nil when
 * unknown (the not_found envelope).
 */
class UpdateCoupon
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.coupons.find_by(id: args[:id]).
        $coupon = Coupon::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $result = UpdateService::call(coupon: $coupon, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->coupon;
    }
}
