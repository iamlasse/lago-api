<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\DataApi\RevenueStreams\CustomersService;

/**
 * Port of Rails' Resolvers::DataApi::RevenueStreams::CustomersResolver
 * (app/graphql/resolvers/data_api/revenue_streams/customers_resolver.rb):
 * "Query revenue streams customers of an organization".
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("data_api:view").
 */
class DataApiRevenueStreamsCustomers
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $result = CustomersService::call(
            organization: LagoContext::currentOrganization($context),
            params: Args::snakeKeys($args),
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: {collection: result.data_x["<key>"], metadata:
        // result.data_x["meta"]} — the Data API payload carries its own
        // pagination metadata.
        $payload = $result->data_revenue_streams_customers;

        return (object) [
            'collection' => is_array($payload) ? ($payload['revenue_streams_customers'] ?? []) : [],
            'metadata' => is_array($payload) ? ($payload['meta'] ?? null) : null,
        ];
    }
}
