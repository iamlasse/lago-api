<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\RegenerateFromVoidedService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::RegenerateFromVoided
 * (app/graphql/mutations/invoices/regenerate_from_voided.rb), graphql_name
 * "RegenerateInvoice": "Regenerate an invoice from a voided invoice" — the
 * voided invoice is looked up among the organization's VISIBLE invoices.
 */
class RegenerateFromVoided
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        $invoice = $organization->invoices()
            ->visible()
            ->find(Args::uuidOrNull($input['voided_invoice_id'] ?? null));

        $result = RegenerateFromVoidedService::call(
            voidedInvoice: $invoice,
            feesParams: (array) ($input['fees'] ?? []),
            purchaseOrderNumber: array_key_exists('purchase_order_number', $input)
                ? $input['purchase_order_number']
                : RegenerateFromVoidedService::PURCHASE_ORDER_NUMBER_INHERIT,
        );

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
