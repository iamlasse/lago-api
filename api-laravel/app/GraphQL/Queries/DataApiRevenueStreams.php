<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\DataApi\RevenueStreamsService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::DataApi::RevenueStreamsResolver
 * (app/graphql/resolvers/data_api/revenue_streams_resolver.rb): "Query
 * revenue streams of an organization".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("data_api:view").
 */
class DataApiRevenueStreams
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = RevenueStreamsService::call(
            organization: LagoContext::currentOrganization($context),
            params: Args::snakeKeys($args),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        $records = $result->revenue_streams;
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
