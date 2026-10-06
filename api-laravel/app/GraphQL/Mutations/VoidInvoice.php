<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\Services\Invoices\VoidService;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::Void
 * (app/graphql/mutations/invoices/void.rb): "Void an invoice" — the
 * generate_credit_note / refund_amount / credit_amount params ride along to
 * Invoices\VoidService (the credit-note generation legs are guarded by the
 * service's premium and amount validations).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:void") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class VoidInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id: args[:id]).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        $params = [];
        foreach (['generate_credit_note', 'refund_amount', 'credit_amount'] as $key) {
            if (array_key_exists($key, $input)) {
                $params[$key] = $input[$key];
            }
        }

        $result = VoidService::call(invoice: $invoice, params: $params);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
