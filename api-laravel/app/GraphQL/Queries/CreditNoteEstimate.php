<?php

declare(strict_types=1);

namespace App\GraphQL\Queries;

use App\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\CreditNotes\EstimateService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Resolvers::CreditNotes::EstimateResolver
 * (app/graphql/resolvers/credit_notes/estimate_resolver.rb): "Fetch amounts
 * for credit note creation" — the invoice is looked up among the
 * organization's VISIBLE invoices (like Rails' invoices.visible scope), so
 * invisible statuses (generating, open, closed, deleted) answer with the
 * not_found envelope.
 */
class CreditNoteEstimate
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $items = Args::snakeKeys($args['items'] ?? []);

        $invoice = $organization
            ->invoices()
            ->whereIn('status', [
                InvoiceStatus::Draft->value,
                InvoiceStatus::Finalized->value,
                InvoiceStatus::Voided->value,
                InvoiceStatus::Failed->value,
                InvoiceStatus::Pending->value,
            ])
            ->find($args['invoiceId'] ?? null);

        $result = EstimateService::call(
            invoice: $invoice,
            items: $items,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->credit_note;
    }
}
