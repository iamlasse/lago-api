<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\CreditNote;
use App\GraphQL\Support\Page;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::InvoiceCreditNotesResolver
 * (app/graphql/resolvers/invoice_credit_notes_resolver.rb): "Query invoice's
 * credit note" — `current_organization.invoices.find(invoice_id)` (any
 * status, unlike the single-invoice resolver) → its FINALIZED credit notes,
 * newest first, kaminari-paginated. Unknown invoices answer with the
 * not_found envelope.
 */
class InvoiceCreditNotes
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?Page
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        /** @var \App\Models\Organization $organization */
        $organization = LagoContext::currentOrganization($context);

        $invoice = $organization
            ->invoices()
            ->find($args['invoiceId'] ?? null);

        if ($invoice === null) {
            throw Errors::notFoundError('invoice');
        }

        [$page, $limit] = Page::normalizePageAndLimit(
            isset($args['page']) ? (int) $args['page'] : null,
            isset($args['limit']) ? (int) $args['limit'] : null,
        );

        $paginator = CreditNote::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', \App\Enums\CreditNoteStatus::Finalized->value)
            ->orderByDesc('created_at')
            ->paginate(perPage: $limit, page: $page);

        return Page::fromLengthAwarePaginator($paginator);
    }
}
