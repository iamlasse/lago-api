<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\CustomerPortalUser;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\WalletTransactions\CreateFromParamsService;

/**
 * Port of Rails' Mutations::CustomerPortal::WalletTransactions::Create
 * (app/graphql/mutations/customer_portal/wallet_transactions/create.rb):
 * "Creates a new Customer Wallet Transaction from Customer Portal" — the
 * paid-credit top-up request on the portal customer's own wallet.
 *
 * Rails merges `customer: context[:customer_portal_user]` into the params so
 * the wallet validation scopes to the portal customer's wallets (the
 * cross-customer top-up answers wallets:top_up's validation error); the
 * result list is wrapped in the frozen SDL's
 * CustomerPortalWalletTransactionCollection shape.
 */
class CreateCustomerPortalWalletTransaction
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        $params = Args::snakeKeys(Args::input($args));
        $params['customer'] = $customer;

        $result = CreateFromParamsService::call(
            organization: $customer->organization,
            params: $params,
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
