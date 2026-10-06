<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\GeneratePdfService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::Download
 * (app/graphql/mutations/invoices/download.rb): "Download an Invoice PDF" —
 * regenerate the PDF (Invoices\GeneratePdfService) and answer the invoice
 * (file_url).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:view") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class DownloadInvoice
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id:).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        $result = GeneratePdfService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
