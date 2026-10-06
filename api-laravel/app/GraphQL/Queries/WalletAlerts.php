<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Queries\UsageMonitoring\AlertsQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Wallets::AlertsResolver
 * (app/graphql/resolvers/wallets/alerts_resolver.rb): "Query alerts of a
 * wallet".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("wallets:update").
 */
class WalletAlerts
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = AlertsQuery::call(
            organization: LagoContext::currentOrganization($context),
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'wallet_id' => $args['walletId'],
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->alerts);
    }
}
