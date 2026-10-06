<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Orders\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Orders::Update
 * (app/graphql/mutations/orders/update.rb): "Update an order's execution
 * settings" — the order is looked up among the organization's orders and
 * Orders\UpdateService applies the execution_mode / execute_at params.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("orders:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class UpdateOrder
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.orders.find_by(id: args[:id]).
        $order = $organization->orders()->find($input['id'] ?? null);

        $result = UpdateService::call(
            order: $order,
            params: collect($input)->except('id')->all(),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->order;
    }
}
