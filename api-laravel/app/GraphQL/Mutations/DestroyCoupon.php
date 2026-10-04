<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Coupon;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Coupons\DestroyService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Coupons::Destroy
 * (app/graphql/mutations/coupons/destroy.rb): "Deletes a coupon" — the
 * payload's `id` is the destroyed coupon's id (Rails resolves
 * result.coupon; the frozen payload type only carries id).
 */
class DestroyCoupon
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

        $result = DestroyService::call(coupon: $coupon);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->coupon;
    }
}
