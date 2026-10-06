<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Emails\ResendService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::ResendEmail
 * (app/graphql/mutations/invoices/resend_email.rb): "Resend invoice email
 * with optional custom recipients" — Emails\ResendService with the to/cc/bcc
 * overrides; the payload is the invoice itself.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:send") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class ResendInvoiceEmail
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id: args[:id]).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        $result = ResendService::call(
            resource: $invoice,
            to: $input['to'] ?? null,
            cc: $input['cc'] ?? null,
            bcc: $input['bcc'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $invoice ?? throw Errors::notFoundError('invoice');
    }
}
