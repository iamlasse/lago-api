<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Queries\WalletTransactionConsumptionsQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::WalletTransactionConsumptionsResolver
 * (app/graphql/resolvers/wallet_transaction_consumptions_resolver.rb):
 * "Query wallet transaction consumptions for an inbound transaction".
 */
class WalletTransactionConsumptions
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = WalletTransactionConsumptionsQuery::call(
            organization: LagoContext::currentOrganization($context),
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'wallet_transaction_id' => $args['walletTransactionId'],
                'direction' => 'consumptions',
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->wallet_transaction_consumptions);
    }
}
