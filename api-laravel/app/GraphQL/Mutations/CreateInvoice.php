<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\CreateOneOffService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::Create
 * (app/graphql/mutations/invoices/create.rb): "Creates a new Invoice" —
 * the one-off invoice flow (Invoices\CreateOneOffService) with the fees,
 * currency, payment-method override, voided-invoice link, custom section,
 * billing entity and purchase-order inputs.
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:create") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class CreateInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.customers.find_by(id: args[:customer_id]).
        $customer = $organization->customers()->find($input['customer_id'] ?? null);

        $result = CreateOneOffService::call(
            customer: $customer,
            currency: $input['currency'] ?? null,
            fees: $input['fees'] ?? [],
            timestamp: now()->timestamp,
            voidedInvoiceId: $input['voided_invoice_id'] ?? null,
            paymentMethodParams: $input['payment_method'] ?? null,
            invoiceCustomSection: $input['invoice_custom_section'] ?? [],
            billingEntityId: $input['billing_entity_id'] ?? null,
            purchaseOrderNumber: $input['purchase_order_number'] ?? null,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
