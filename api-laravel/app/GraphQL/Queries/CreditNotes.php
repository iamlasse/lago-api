<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\GraphQL\Support\Args;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\Queries\CreditNotesQuery;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CreditNotesResolver
 * (app/graphql/resolvers/credit_notes_resolver.rb): "Query credit notes" —
 * the full frozen-SDL filter set (amount range, billing entities, credit
 * status, currency, customer, invoice number, issuing-date range, purchase
 * order number, reason, refund status, self-billed, types) and the search
 * term through the `CreditNotes\Query` port, wrapped in the frozen SDL's
 * CreditNoteCollection shape.
 */
class CreditNotes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $filters = Args::snakeKeys($args);
        unset($filters['page'], $filters['limit'], $filters['searchTerm']);

        $result = CreditNotesQuery::call(
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

        return Page::fromLengthAwarePaginator($result->credit_notes);
    }
}
