<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Support\BillingEntityArgs;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Analytics\InvoiceCollectionsService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Analytics::InvoiceCollectionsResolver
 * (app/graphql/resolvers/analytics/invoice_collections_resolver.rb): "Query
 * invoice collections of an organization" — premium-only, the months filter
 * is pinned to 12.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("analytics:view").
 */
class InvoiceCollections
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

        $result = InvoiceCollectionsService::call(
            organization: $organization,
            filters: $filters,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $this->collection($result->records);
    }

    /** graphql-pagination's default metadata for a non-paginated list. */
    protected function collection(array $records): object
    {
        $count = count($records);

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
