<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Invoices\UpdateService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::Update
 * (app/graphql/mutations/invoices/update.rb): "Update an existing invoice" —
 * the invoice is looked up among the organization's VISIBLE invoices; a
 * voided invoice answers the not_allowed `update_on_voided_invoice` error
 * before the service runs. Invoices\UpdateService carries the
 * payment_status / metadata updates with the webhook notification.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class UpdateInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: context[:current_organization].invoices.visible.find_by(id: args[:id]).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        if ($invoice?->isVoided()) {
            // Rails: not_allowed_error(code: "update_on_voided_invoice").
            throw Errors::notAllowedError('update_on_voided_invoice');
        }

        $result = UpdateService::call(
            invoice: $invoice,
            params: $input,
            webhookNotification: true,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
