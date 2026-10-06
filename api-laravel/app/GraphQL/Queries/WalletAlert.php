<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Models\UsageMonitoring\Alert;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Wallets::AlertResolver
 * (app/graphql/resolvers/wallets/alert_resolver.rb): "Query a single wallet
 * alert" — scoped to the org's wallet-type alerts.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("wallets:update").
 */
class WalletAlert
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?Alert
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $alert = Alert::query()
            ->where('organization_id', $organization->id)
            ->usingWallet()
            ->find($args['id'] ?? null);

        if ($alert === null) {
            throw Errors::notFoundError('alert');
        }

        return $alert;
    }
}
