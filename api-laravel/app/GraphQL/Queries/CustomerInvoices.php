<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Customer;
use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\Query as InvoicesQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::Customers::InvoicesResolver
 * (app/graphql/resolvers/customers/invoices_resolver.rb): "Query invoices of
 * a customer" — the customer_id-scoped InvoicesQuery with the status /
 * currency / billing-entity filters and the search term; an unknown customer
 * answers the not_found envelope (Rails' rescue RecordNotFound).
 */
class CustomerInvoices
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        if ($organization->customers()->whereKey($args['customerId'] ?? null)->doesntExist()) {
            throw Errors::notFoundError('customer');
        }

        $filters = Args::snakeKeys($args);
        unset($filters['page'], $filters['limit'], $filters['searchTerm']);

        $result = InvoicesQuery::call(
            organization: $organization,
            filters: $filters,
            pagination: [
                'page' => $args['page'] ?? null,
                'limit' => $args['limit'] ?? null,
            ],
            searchTerm: $args['searchTerm'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return Page::fromLengthAwarePaginator($result->invoices);
    }
}
