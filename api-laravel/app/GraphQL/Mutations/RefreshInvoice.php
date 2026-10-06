<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\RefreshDraftService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::Refresh
 * (app/graphql/mutations/invoices/refresh.rb): "Refresh a draft invoice" —
 * the invoice is looked up among the organization's VISIBLE invoices, so an
 * unknown or invisible id answers the not_found envelope before the service
 * runs (Rails passes the nil invoice to RefreshDraftService, which fails
 * with not_found; the ported service requires an invoice, so the guard
 * short-circuits here with the same envelope).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class RefreshInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id: args[:id]).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        if ($invoice === null) {
            throw Errors::notFoundError('invoice');
        }

        $result = RefreshDraftService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
