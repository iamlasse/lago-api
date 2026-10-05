<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\UsageMonitoring\Alert;
use Illuminate\Database\Eloquent\Model;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\UsageMonitoring\UpdateAlertService;

/**
 * Port of Rails' Mutations::Subscriptions::Alerts::Update
 * (app/graphql/mutations/subscriptions/alerts/update.rb): "Updates an alert"
 * — the alert found among the current organization's subscription alerts.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("subscriptions:update") lands
 * with the roles/Permission slice (like the other ported mutations).
 */
class UpdateSubscriptionAlert
{
    public function __invoke(mixed $root, array $args, mixed $context): Model
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));
        $alertId = $input['id'] ?? null;

        $alert = is_string($alertId)
            ? $organization->alerts()->usingSubscription()->find($alertId)
            : null;

        $result = UpdateAlertService::call(alert: $alert, params: $input);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->alert;
    }
}
