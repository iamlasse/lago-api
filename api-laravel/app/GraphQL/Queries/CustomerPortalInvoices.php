<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Guards\CustomerPortalUser;
use App\GraphQL\Support\LagoContext;
use App\Services\Invoices\Query as InvoicesQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CustomerPortal::InvoicesResolver
 * (app/graphql/resolvers/customer_portal/invoices_resolver.rb): "Query
 * invoices of a customer" — the portal customer's own visible invoices
 * through the `Invoices\Query` port, wrapped in the frozen SDL's
 * InvoiceCollection shape.
 *
 * Rails passes the Customer ITSELF as the query's `organization` (duck-typed
 * `organization.invoices`), pinned to `filters.customer_id`; the ported query
 * type-hints Organization, so the customer's organization is passed instead —
 * combined with the same customer_id filter the scope is identical (the
 * customer belongs to one organization), and the search term narrows
 * `invoices.number` exactly like Rails (a customer_id filter is present).
 *
 * Rails' extra `Invoice.preload_offset_amounts` is the offset-amounts
 * preload on the fee aggregates — the ported query carries the visible
 * statuses by default (TODO(port): the preload).
 */
class CustomerPortalInvoices
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        $result = InvoicesQuery::call(
            organization: $customer->organization,
            filters: [
                'customer_id' => $customer->id,
                'status' => $args['status'] ?? null,
            ],
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
