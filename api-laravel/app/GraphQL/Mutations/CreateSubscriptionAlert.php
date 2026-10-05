<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use Illuminate\Database\Eloquent\Model;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\UsageMonitoring\CreateAlertService;

/**
 * Port of Rails' Mutations::Subscriptions::Alerts::Create
 * (app/graphql/mutations/subscriptions/alerts/create.rb): "Creates a new
 * Alert for subscription" — the subscription resolved by id from the current
 * organization, params passed straight through.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("subscriptions:update") lands
 * with the roles/Permission slice (like the other ported mutations).
 */
class CreateSubscriptionAlert
{
    public function __invoke(mixed $root, array $args, mixed $context): Model
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));
        $subscriptionId = $input['subscription_id'] ?? null;

        $subscription = is_string($subscriptionId)
            ? $organization->subscriptions()->find($subscriptionId)
            : null;

        if ($subscription === null) {
            throw Errors::notFoundError('subscription');
        }

        $result = CreateAlertService::call(
            organization: $organization,
            alertable: $subscription,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->alert;
    }
}
