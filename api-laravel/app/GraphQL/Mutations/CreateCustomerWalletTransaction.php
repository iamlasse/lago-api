<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\WalletTransactions\CreateFromParamsService;

/**
 * Port of Rails' Mutations::WalletTransactions::Create
 * (app/graphql/mutations/wallet_transactions/create.rb): "Creates a new
 * Customer Wallet Transaction" — the service creates the paid, granted
 * and/or voided transactions in one call and the result list is wrapped in
 * the frozen SDL's WalletTransactionCollection shape.
 *
 * Rails returns the raw Array to graphql-pagination's collection_type,
 * whose default metadata for a non-paginated list reports page 1, a
 * limit/total of the list size and a single page — mirrored here.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("wallets:top_up") lands with
 * the roles/Permission slice (context permissions are not populated yet —
 * every ported mutation waits on it).
 */
class CreateCustomerWalletTransaction
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = CreateFromParamsService::call(
            organization: $organization,
            params: Args::snakeKeys(Args::input($args)),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        $transactions = $result->wallet_transactions;
        $count = is_countable($transactions) ? count($transactions) : 0;

        return new Page(
            collection: $transactions,
            metadata: (object) [
                'currentPage' => 1,
                'limitValue' => $count,
                'totalPages' => 1,
                'totalCount' => $count,
            ],
        );
    }
}
