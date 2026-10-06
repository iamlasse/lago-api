<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Support\BillingEntityArgs;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Analytics\InvoicedUsagesService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Analytics::InvoicedUsagesResolver
 * (app/graphql/resolvers/analytics/invoiced_usages_resolver.rb): "Query
 * invoiced usage of an organization" — premium-only, months pinned to 12.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("analytics:view").
 */
class InvoicedUsages
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        // Rails: raise unauthorized_error unless License.premium?
        if (! \App\Support\License::premium()) {
            throw new \App\GraphQL\Exceptions\ExecutionError('unauthorized', 'unauthorized', 'unauthorized');
        }

        $organization = LagoContext::currentOrganization($context);

        $filters = Args::snakeKeys($args);
        $filters['months'] = 12;

        if (($error = BillingEntityArgs::resolve($organization, $filters)) !== null) {
            throw $error;
        }

        $result = InvoicedUsagesService::call(
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
