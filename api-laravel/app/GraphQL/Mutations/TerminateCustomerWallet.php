<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Wallet;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Wallets\TerminateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Wallets::Terminate
 * (app/graphql/mutations/wallets/terminate.rb): "Terminates a new Customer
 * Wallet" — the wallet resolved from the current organization; a missing
 * id lets the terminate service answer not_found.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("wallets:terminate") lands with
 * the roles/Permission slice (context permissions are not populated yet —
 * every ported mutation waits on it).
 */
class TerminateCustomerWallet
{
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.wallets.find_by(id:) — a non-uuid id
        // finds nothing.
        $walletId = $input['id'] ?? null;

        $wallet = is_string($walletId) && preg_match(self::UUID_REGEX, $walletId) === 1
            ? Wallet::query()->where('organization_id', $organization->id)->find($walletId)
            : null;

        $result = TerminateService::call(wallet: $wallet);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->wallet;
    }
}
