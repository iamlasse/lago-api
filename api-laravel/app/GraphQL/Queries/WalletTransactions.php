<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Queries\WalletTransactionsQuery;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WalletTransactionsResolver
 * (app/graphql/resolvers/wallet_transactions_resolver.rb): "Query wallet
 * transactions" — the wallet must exist (not_found envelope otherwise),
 * status / transaction_type filters pass through the
 * WalletTransactionsQuery port, wrapped in the frozen SDL's
 * WalletTransactionCollection shape (`collection` + `metadata`).
 */
class WalletTransactions
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = WalletTransactionsQuery::call(
            organization: $organization,
            walletId: $args['walletId'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'status' => $args['status'] ?? null,
                'transaction_type' => $args['transactionType'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->wallet_transactions);
    }
}
