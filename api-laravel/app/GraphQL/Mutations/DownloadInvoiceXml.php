<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\Models\Invoice;
use App\GraphQL\Support\Args;
use App\GraphQL\Execution\Errors;
use App\GraphQL\Support\LagoContext;
use App\GraphQL\Guards\AuthenticableApiUser;
use App\GraphQL\Guards\RequiredOrganization;
use App\Services\Invoices\GenerateXmlService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

/**
 * Port of Rails' Mutations::Invoices::DownloadXml
 * (app/graphql/mutations/invoices/download_xml.rb): "Download an Invoice
 * XML" — Invoices\GenerateXmlService and answer the invoice (xml_url).
 *
 * TODO(port): the REQUIRED_PERMISSION gate ("invoices:view") lands with
 * the roles/Permission slice (context permissions are not populated yet).
 */
class DownloadInvoiceXml
{
    public function __invoke(mixed $root, array $args, GraphQLContext $context): object
    {
        AuthenticableApiUser::authorize($context);
        RequiredOrganization::authorize($context);

        $organization = LagoContext::currentOrganization($context);

        $input = Args::snakeKeys(Args::input($args));

        // Rails: current_organization.invoices.visible.find_by(id:).
        $invoice = $organization->invoices()->visible()->find($input['id'] ?? null);

        $result = GenerateXmlService::call(invoice: $invoice);

        if ($result->failure()) {
            throw Errors::resultError($result->getError());
        }

        return $result->invoice;
    }
}
