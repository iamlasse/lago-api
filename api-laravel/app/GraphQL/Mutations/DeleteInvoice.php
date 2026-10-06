<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Invoices\DeleteService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::Delete
 * (app/graphql/mutations/invoices/delete.rb): "Delete a draft invoice" —
 * the invoice is looked up among the organization's VISIBLE invoices, so an
 * unknown or invisible id reaches the service as nil and answers the
 * not_found envelope. Invoices::DeleteService carries the draft-only guard
 * (not_deletable) and the external-sync guard.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:delete") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class DeleteInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id: args[:id]).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        $result = DeleteService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
