<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\DataApi\Mrrs\PlansService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::DataApi::Mrrs::PlansResolver
 * (app/graphql/resolvers/data_api/mrrs/plans_resolver.rb): "Query monthly
 * recurring revenues plans of an organization".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("data_api:view").
 */
class DataApiMrrsPlans
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = PlansService::call(
            organization: LagoContext::currentOrganization($context),
            params: Args::snakeKeys($args),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: {collection: result.data_x["<key>"], metadata:
        // result.data_x["meta"]} — the Data API payload carries its own
        // pagination metadata.
        $payload = $result->data_mrrs_plans;

        return (object) [
            'collection' => is_array($payload) ? ($payload['mrrs_plans'] ?? []) : [],
            'metadata' => is_array($payload) ? ($payload['meta'] ?? null) : null,
        ];
    }
}
