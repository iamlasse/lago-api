<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\Wallet as WalletModel;
use App\GraphQL\Guards\CustomerPortalUser;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::WalletResolver
 * (app/graphql/resolvers/customer_portal/wallet_resolver.rb): "Query a
 * single wallet from the customer portal" — the portal customer's own
 * wallet by id; anything else answers the not_found envelope.
 */
class CustomerPortalWallet
{
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?WalletModel
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        // Rails: customer.wallets.find(id) — a non-uuid id raises
        // RecordNotFound (never a PG cast error); guard the id shape.
        $id = $args['id'] ?? null;

        if (! is_string($id) || preg_match(self::UUID_REGEX, $id) !== 1) {
            throw Errors::notFoundError('wallet');
        }

        $found = $customer->wallets()->find($id);

        if ($found === null) {
            throw Errors::notFoundError('wallet');
        }

        return $found;
    }
}
