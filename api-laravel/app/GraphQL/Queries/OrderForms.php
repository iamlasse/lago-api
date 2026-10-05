<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\Queries\OrderFormsQuery;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::OrderFormsResolver (app/graphql/resolvers/
 * order_forms_resolver.rb): "Query order forms" — the filters and the search
 * term go through the OrderFormsQuery port, wrapped in the frozen SDL's
 * OrderFormCollection shape (`collection` + `metadata`).
 */
class OrderForms
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $result = OrderFormsQuery::call(
            organization: $organization,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            filters: [
                'status' => $args['status'] ?? null,
                'customer_id' => $args['customerId'] ?? null,
                'number' => $args['number'] ?? null,
                'quote_number' => $args['quoteNumber'] ?? null,
                'owner_id' => $args['ownerId'] ?? null,
                'created_at_from' => $args['createdAtFrom'] ?? null,
                'created_at_to' => $args['createdAtTo'] ?? null,
                'expires_at_from' => $args['expiresAtFrom'] ?? null,
                'expires_at_to' => $args['expiresAtTo'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->order_forms);
    }
}
