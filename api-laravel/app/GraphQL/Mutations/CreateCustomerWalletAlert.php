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
 * Port of Rails' Mutations::Wallets::Alerts::Create
 * (app/graphql/mutations/wallets/alerts/create.rb): "Creates a new Alert for
 * wallet" — the wallet resolved by id from the current organization.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("wallets:update") lands with the
 * roles/Permission slice (like the other ported mutations).
 */
class CreateCustomerWalletAlert
{
    public function __invoke(mixed $root, array $args, mixed $context): Model
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));
        $walletId = $input['wallet_id'] ?? null;

        $wallet = is_string($walletId)
            ? \App\Models\Wallet::query()->where('organization_id', $organization->id)->find($walletId)
            : null;

        if ($wallet === null) {
            throw Errors::notFoundError('wallet');
        }

        $result = CreateAlertService::call(
            organization: $organization,
            alertable: $wallet,
            params: $input,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->alert;
    }
}
