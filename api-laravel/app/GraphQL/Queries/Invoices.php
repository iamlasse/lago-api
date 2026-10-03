<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\Query as InvoicesQuery;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::InvoicesResolver
 * (app/graphql/resolvers/invoices_resolver.rb): "Query invoices" — the full
 * frozen-SDL filter set (amount range, billing entities, currency, customer,
 * invoice type, issuing-date range, payment flags/status, purchase order
 * number, search term, self-billed, status, subscription id) through the
 * `Invoices\Query` port, wrapped in the frozen SDL's InvoiceCollection shape
 * (`collection` + `metadata`).
 *
 * Rails extends the result with BaseQuery::CappedTotalCount (10k) — the two
 * capped metadata fields are computed by
 * App\GraphQL\Types\InvoiceCollectionMetadata. Rails' extra preloads
 * (:regenerated_invoice, :error_details, :customer_payments) stay unported.
 */
class Invoices
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

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
