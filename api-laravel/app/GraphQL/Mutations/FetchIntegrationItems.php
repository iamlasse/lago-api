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
use App\Services\Integrations\Aggregator\ItemsService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::IntegrationItems::FetchItems
 * (app/graphql/mutations/integration_items/fetch_items.rb): "Fetch
 * integration items" — a Nango sync restricted to items, then the items
 * pull.
 *
 * TODO(port): the REQUIRED_PERMISSION gate
 * ("organization:integrations:update").
 */
class FetchIntegrationItems
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;

        $integration = Integration::query()
            ->where('organization_id', LagoContext::currentOrganization($context)->id)
            ->where('id', $input['integrationId'] ?? null)
            ->first();

        // Rails: SyncService.call(integration:, options: {only_items:
        // true}) — the ported SyncService carries the documented TODO for
        // the only_items narrowing.
        SyncService::call(integration: $integration);

        $result = ItemsService::call(integration: $integration);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        $items = $result->items;
        $count = is_countable($items) ? count($items) : 0;

        return new Page(
            collection: $items,
            metadata: (object) [
                'currentPage' => 1,
                'limitValue' => $count,
                'totalPages' => 1,
                'totalCount' => count($items),
            ],
        );
    }
}
