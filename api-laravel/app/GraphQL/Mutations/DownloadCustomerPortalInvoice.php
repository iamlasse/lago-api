<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\CustomerPortalUser;
use App\Services\Invoices\GeneratePdfService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::CustomerPortal::DownloadInvoice
 * (app/graphql/mutations/customer_portal/download_invoice.rb): "Download
 * customer portal invoice PDF" — the portal customer's own VISIBLE invoice,
 * regenerated through Invoices\GeneratePdfService.
 */
class DownloadCustomerPortalInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): ?object
    {
        CustomerPortalUser::authorize($context);

        /** @var \App\Models\Customer $customer */
        $customer = LagoContext::customerPortalUser($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: customer.invoices.visible.find_by(id:) — an invisible (or
        // foreign) invoice falls through to the service's not_found.
        $invoice = $customer->invoices()->visible()->find($input['id'] ?? null);

        $result = GeneratePdfService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
