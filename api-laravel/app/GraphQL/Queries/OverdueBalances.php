<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Support\BillingEntityArgs;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Analytics\OverdueBalancesService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Analytics::OverdueBalancesResolver
 * (app/graphql/resolvers/analytics/overdue_balances_resolver.rb): "Query
 * overdue balances of an organization".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("analytics:view").
 */
class OverdueBalances
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $filters = Args::snakeKeys($args);

        if (($error = BillingEntityArgs::resolve($organization, $filters)) !== null) {
            throw $error;
        }

        $result = OverdueBalancesService::call(
            organization: $organization,
            filters: $filters,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        $records = $result->records;
        $count = is_countable($records) ? count($records) : 0;

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
