<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use Illuminate\Database\Eloquent\Model;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\UsageMonitoring\DestroyAlertService;

/**
 * Port of Rails' Mutations::Wallets::Alerts::Destroy
 * (app/graphql/mutations/wallets/alerts/destroy.rb): "Deletes an alert" —
 * found among the current organization's wallet alerts.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("wallets:update") lands with the
 * roles/Permission slice (like the other ported mutations).
 */
class DestroyCustomerWalletAlert
{
    public function __invoke(mixed $root, array $args, mixed $context): Model
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));
        $alertId = $input['id'] ?? null;

        $alert = is_string($alertId)
            ? $organization->alerts()->usingWallet()->find($alertId)
            : null;

        $result = DestroyAlertService::call(alert: $alert);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->alert;
    }
}
