<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Models\WalletTransaction as WalletTransactionModel;

/**
 * Port of Rails' Resolvers::WalletTransactionResolver
 * (app/graphql/resolvers/wallet_transaction_resolver.rb): "Query a single
 * wallet transaction" — `current_organization.wallet_transactions
 * .includes(:invoice).find(id)`, so a transaction of another organization
 * answers the not_found envelope exactly like Rails.
 */
class WalletTransaction
{
    /** Rails: BaseQuery::UUID_REGEX — the uuid attribute cast's valid shape. */
    private const UUID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?WalletTransactionModel
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        // Rails: find_by/find with a non-uuid id answers the not_found
        // envelope (the uuid attribute cast never reaches a PG cast error);
        // guard the id shape before querying.
        $id = $args['id'] ?? null;

        if (! is_string($id) || preg_match(self::UUID_REGEX, $id) !== 1) {
            throw Errors::notFoundError('wallet_transaction');
        }

        $found = WalletTransactionModel::query()
            ->where('organization_id', $organization->id)
            ->with('invoice')
            ->find($id);

        if ($found === null) {
            throw Errors::notFoundError('wallet_transaction');
        }

        return $found;
    }
}
