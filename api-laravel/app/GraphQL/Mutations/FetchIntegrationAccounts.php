<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Integration;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Integrations\Aggregator\SyncService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Integrations\Aggregator\AccountsService;

/**
 * Port of Rails' Mutations::IntegrationItems::FetchAccounts
 * (app/graphql/mutations/integration_items/fetch_accounts.rb): "Fetch
 * integration accounts" — a Nango sync restricted to accounts, then the
 * accounts pull.
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:update").
 */
class FetchIntegrationAccounts
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        // Rails: current_organization.integrations.find_by(id:) — a missing
        // integration flows into the services' nil branches.
        $integration = Integration::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['integrationId'] ?? null)
            ->first();

        // Rails: SyncService.call(integration:, options: {only_accounts:
        // true}) — the ported SyncService carries the documented TODO for
        // the only_accounts narrowing.
        SyncService::call(integration: $integration);

        $result = AccountsService::call(integration: $integration);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        $accounts = $result->accounts;
        $count = is_countable($accounts) ? count($accounts) : 0;

        return new Page(
            collection: $accounts,
            metadata: (object) [
                'currentPage' => 1,
                'limitValue' => $count,
                'totalPages' => 1,
                'totalCount' => count($accounts),
            ],
        );
    }
}
