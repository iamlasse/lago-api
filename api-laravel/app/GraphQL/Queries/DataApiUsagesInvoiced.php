<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\DataApi\Usages\InvoicedService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::DataApi::Usages::InvoicedResolver
 * (app/graphql/resolvers/data_api/usages/invoiced_resolver.rb): "Query
 * invoiced usages of an organization".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("data_api:view").
 */
class DataApiUsagesInvoiced
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = InvoicedService::call(
            organization: LagoContext::currentOrganization($context),
            params: Args::snakeKeys($args),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        $records = $result->invoiced_usages;
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
