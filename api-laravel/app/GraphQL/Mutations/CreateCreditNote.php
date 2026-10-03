<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\CreditNotes\CreateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CreditNotes::Create
 * (app/graphql/mutations/credit_notes/create.rb): "Creates a new Credit
 * Note" — the invoice is looked up among the organization's VISIBLE
 * invoices, so unknown or invisible ids reach the service as nil and answer
 * with the not_found envelope.
 *
 * TODO(port): the `metadata` input is accepted but dropped until the
 * Metadata::ItemMetadata model is ported.
 */
class CreateCreditNote
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $invoice = $organization
            ->invoices()
            ->whereIn('status', [
                \App\Enums\InvoiceStatus::Draft->value,
                \App\Enums\InvoiceStatus::Finalized->value,
                \App\Enums\InvoiceStatus::Voided->value,
                \App\Enums\InvoiceStatus::Failed->value,
                \App\Enums\InvoiceStatus::Pending->value,
            ])
            ->find($input['invoice_id'] ?? null);

        $result = CreateService::call(
            invoice: $invoice,
            items: $input['items'] ?? null,
            reason: $input['reason'] ?? null,
            description: $input['description'] ?? null,
            creditAmountCents: isset($input['credit_amount_cents']) ? (int) $input['credit_amount_cents'] : 0,
            refundAmountCents: isset($input['refund_amount_cents']) ? (int) $input['refund_amount_cents'] : 0,
            offsetAmountCents: isset($input['offset_amount_cents']) ? (int) $input['offset_amount_cents'] : 0,
            metadata: $input['metadata'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->credit_note;
    }
}
