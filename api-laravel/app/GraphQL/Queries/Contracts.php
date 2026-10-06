<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\Queries\ContractsQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\GraphQL\Support\RequiresProductCatalog;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::ContractsResolver (app/graphql/resolvers/contracts_resolver.rb):
 * "Query contracts of an organization" — product_catalog-gated; the filters
 * go through the ContractsQuery port, wrapped in the frozen SDL's
 * ContractCollection shape.
 */
class Contracts
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);
        RequiresProductCatalog::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = ContractsQuery::call(
            organization: $organization,
            searchTerm: $args['searchTerm'] ?? null,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'external_customer_id' => $args['externalCustomerId'] ?? null,
                'external_id' => $args['externalId'] ?? null,
                'plan_code' => $args['planCode'] ?? null,
                'status' => $args['status'] ?? null,
                'billing_entity_ids' => $args['billingEntityIds'] ?? null,
                'has_rate_overrides' => $args['hasRateOverrides'] ?? null,
            ],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->contracts);
    }
}
