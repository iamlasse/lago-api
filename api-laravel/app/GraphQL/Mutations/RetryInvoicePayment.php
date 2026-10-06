<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\Payments\RetryService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::RetryPayment
 * (app/graphql/mutations/invoices/retry_payment.rb): "Retry invoice
 * payment" — the visible invoice + the optional payment-method override go
 * to Invoices\Payments\RetryService (the async payment creation re-run).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:update") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class RetryInvoicePayment
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id: args[:id]).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        $result = RetryService::call(
            invoice: $invoice,
            paymentMethodParams: $input['payment_method'] ?? [],
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
