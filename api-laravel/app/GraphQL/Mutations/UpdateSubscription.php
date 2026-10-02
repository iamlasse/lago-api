<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Subscriptions\UpdateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Subscriptions::Update
 * (app/graphql/mutations/subscriptions/update.rb): "Update a Subscription" —
 * the whole input (snake_cased, `id` included) goes to the service, exactly
 * like Rails (`Subscriptions::UpdateService.call(subscription:, params:
 * args)`).
 */
class UpdateSubscription
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.subscriptions.find_by(id: args[:id])
        $subscription = $organization->subscriptions()->find($input['id'] ?? null);

        $result = UpdateService::call(
            subscription: $subscription,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: subscription.reload
        return $result->subscription->refresh();
    }
}
