<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Coupons\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Coupons::Create
 * (app/graphql/mutations/coupons/create.rb): "Creates a new Coupon" — the
 * whole input goes to Coupons::CreateService merged with the current
 * organization id.
 */
class CreateCoupon
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $result = CreateService::call(args: array_merge($input, [
            'organization_id' => $organization->id,
        ]));

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->coupon;
    }
}
