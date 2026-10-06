<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\DataApi\MrrsService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::DataApi::MrrsResolver
 * (app/graphql/resolvers/data_api/mrrs_resolver.rb): "Query monthly
 * recurring revenues of an organization".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("data_api:view").
 */
class DataApiMrrs
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = MrrsService::call(
            organization: LagoContext::currentOrganization($context),
            params: Args::snakeKeys($args),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        $records = $result->mrrs;
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
