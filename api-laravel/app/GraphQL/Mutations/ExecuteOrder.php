<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Order;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Orders\ExecuteService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Orders::Execute
 * (app/graphql/mutations/orders/execute.rb): "Execute an order" — the
 * execution_mode restated here is the only way to set or change it on the
 * GraphQL surface (same hook the REST POST /orders/:id/execute uses).
 *
 * Rails rescues BaseLockService::FailedToAcquireLock with a
 * concurrency_conflict validation message; the port answers the same
 * envelope for the lock-timeout paths.
 */
class ExecuteOrder
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.orders.find_by(id: args[:id]).
        $order = Order::query()
            ->where('organization_id', $organization->id)
            ->find($input['id'] ?? null);

        $executionMode = $input['execution_mode'] ?? null;

        if (is_string($executionMode) && $executionMode !== ''
            && $order !== null
            && $order->execution_mode?->value !== $executionMode) {
            $updateResult = \App\Services\Orders\UpdateService::call(
                order: $order,
                params: ['execution_mode' => $executionMode],
            );

            if ($updateResult->failure()) {
                throw Errors::resultError($updateResult->getError());
            }
        }

        $result = ExecuteService::call(order: $order);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->order;
    }
}
