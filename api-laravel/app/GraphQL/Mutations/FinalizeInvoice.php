<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Enums\InvoiceStatus;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Invoices\FinalizeService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::Finalize
 * (app/graphql/mutations/invoices/finalize.rb): "Finalize a draft invoice" —
 * the invoice is looked up among the organization's DRAFT invoices only
 * (`current_organization.invoices.draft.find_by(id:)`), so an unknown or
 * non-draft id reaches the service as nil and answers with the
 * `not_found` envelope.
 *
 * Rails runs Invoices::RefreshDraftAndFinalizeService (refresh + finalize +
 * the async document/webhook tail); the ported
 * Invoices\FinalizeService carries the draft → finalized transition with
 * the model's numbering hooks. The refresh/webhook tail lands with the
 * invoice-generation slice (TODO(port)).
 */
class FinalizeInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.draft.find_by(id: args[:id])
        $invoice = $organization
            ->invoices()
            ->where('status', InvoiceStatus::Draft->value)
            ->find($input['id'] ?? null);

        $result = FinalizeService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        // Rails: result.invoice (reloaded inside the service's with_lock).
        return $result->invoice;
    }
}
