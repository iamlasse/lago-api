<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\LagoContext;
use App\Models\Analytics\OverdueBalance;
use App\GraphQL\Guards\CustomerPortalUser;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::Analytics::OverdueBalancesResolver
 * (app/graphql/resolvers/customer_portal/analytics/overdue_balances_resolver.rb):
 * "Query overdue balances of a customer portal user" — pinned to the portal
 * customer's currency and external_id.
 *
 * Unlike the admin resolver, Rails calls `Analytics::OverdueBalance.
 * find_all_by` DIRECTLY (no premium gate, no service) — so does the port.
 */
class CustomerPortalOverdueBalances
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        $filters = Args::snakeKeys($args);

        $filters['currency'] = $customer->currency;
        $filters['external_customer_id'] = $customer->external_id;

        $records = OverdueBalance::findAllBy($customer->organization_id, $filters);
        $count = count($records);

        // graphql-pagination's default metadata for a non-paginated list.
        return (object) [
            'collection' => $records,
            'metadata' => (object) [
                'currentPage' => 1,
                'limitValue' => $count,
                'totalPages' => 1,
                'totalCount' => $count,
            ],
        ];
    }
}
