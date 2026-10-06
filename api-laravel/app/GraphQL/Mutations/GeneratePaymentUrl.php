<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use App\Services\Invoices\Payments\GeneratePaymentUrlService;

/**
 * Port of Rails' Mutations::Invoices::GeneratePaymentUrl
 * (app/graphql/mutations/invoices/generate_payment_url.rb): "Generates a
 * payment URL for an invoice" — the visible invoice goes to
 * Invoices\Payments\GeneratePaymentUrlService; the payload is
 * {payment_url, clientMutationId}.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class GeneratePaymentUrl
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id: invoice_id).
        $invoice = $organization->invoices()->visible()->find($input['invoice_id'] ?? null);

        if ($invoice === null) {
            throw Errors::notFoundError('invoice');
        }

        $result = GeneratePaymentUrlService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return [
            'payment_url' => $result->payment_url,
            'client_mutation_id' => $args['input']['clientMutationId'] ?? null,
        ];
    }
}
